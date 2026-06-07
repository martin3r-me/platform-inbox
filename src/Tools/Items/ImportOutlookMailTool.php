<?php

namespace Platform\Inbox\Tools\Items;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Enums\InboxItemStatus;
use Symfony\Component\Uid\UuidV7;

/**
 * Holt eine historische Mail aus Outlook (per Graph-Message-ID) und legt
 * sie nachträglich als Inbox-Item an. Schließt die Lücke „User-Connectors
 * gab's damals noch nicht" — eine wichtige alte Mail kann so trotzdem in
 * den Triage-Pfad gezogen werden (Entity-Verknüpfung, Task-Handoff,
 * Anreicherung etc.).
 *
 * Erzeugt zwei Rows:
 *   1. user_connector_mail_sessions (damit Inbox-Item einen sauberen
 *      Source-Anker hat und z. B. mail.reply / mail.forward funktionieren)
 *   2. inbox_items (status=new, Standard-Triage)
 *
 * Idempotent über die (connection_id, external_mail_id) UNIQUE der
 * Mail-Sessions: ein zweiter Import derselben Mail gibt das bestehende
 * Item zurück, ohne Duplikat zu erzeugen.
 */
class ImportOutlookMailTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.items.import_from_outlook.POST';
    }

    public function getDescription(): string
    {
        return 'Importiert eine historische Outlook-Mail (per Graph-Message-ID) nachträglich '
            . 'als Inbox-Item. Nützlich für wichtige Mails, die vor dem Einsatz der '
            . 'User-Connectors gesendet/empfangen wurden. Idempotent — Re-Import gibt das '
            . 'bestehende Item zurück.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'external_mail_id' => [
                    'type' => 'string',
                    'description' => 'MS-Graph-Message-ID der Mail.',
                ],
                'connection_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. Default: erste aktive microsoft365-Connection des Users.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['new', 'done'],
                    'description' => 'Inbox-Status für das neue Item. Default: new.',
                ],
            ],
            'required' => ['external_mail_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }
        $teamId = $context->team?->id ?? $context->user->currentTeam?->id;
        if (!$teamId) {
            return ToolResult::error('AUTH_ERROR', 'Kein Team im Kontext.');
        }

        $mailId = trim((string) ($arguments['external_mail_id'] ?? ''));
        if ($mailId === '') {
            return ToolResult::error('VALIDATION_ERROR', 'external_mail_id ist erforderlich.');
        }
        $status = ($arguments['status'] ?? 'new') === 'done'
            ? InboxItemStatus::Done->value
            : InboxItemStatus::New->value;

        // Resolve the microsoft365 connection. connector_key lives on
        // user_connectors.key, not on the connections row — join over
        // connector_id.
        $connectionId = $arguments['connection_id'] ?? null;
        if (!$connectionId) {
            $connectionId = DB::table('user_connector_connections as c')
                ->join('user_connectors as uc', 'uc.id', '=', 'c.connector_id')
                ->where('c.owner_user_id', $context->user->id)
                ->where('uc.key', 'microsoft365')
                ->where('c.status', 'active')
                ->orderByDesc('c.id')
                ->value('c.id');
        }
        if (!$connectionId) {
            return ToolResult::error('NOT_FOUND', 'Keine aktive microsoft365-Connection für diesen User.');
        }

        // Idempotency: Re-Import gibt bestehendes Item zurück.
        $existingSession = DB::table('user_connector_mail_sessions')
            ->where('connection_id', $connectionId)
            ->where('external_mail_id', $mailId)
            ->first(['id']);
        if ($existingSession) {
            $existingItem = DB::table('inbox_items')
                ->where('source_type', 'user_connector_mail_session')
                ->where('source_id', $existingSession->id)
                ->first(['id']);
            if ($existingItem) {
                return ToolResult::success([
                    'inbox_item_id' => (int) $existingItem->id,
                    'mail_session_id' => (int) $existingSession->id,
                    'duplicate' => true,
                    'message' => 'Mail war schon importiert — vorhandenes Item zurückgegeben.',
                ]);
            }
        }

        // Fetch the mail body from Graph.
        try {
            $connector = new \Platform\UserConnectors\Services\Microsoft365\Microsoft365MailConnector(
                app(\Platform\UserConnectors\Services\Microsoft365\Microsoft365ApiService::class)
                    ->forConnection((int) $connectionId)
            );
            $msg = $connector->getMessage($context->user, $mailId);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Graph-Abruf fehlgeschlagen: ' . $e->getMessage());
        }

        $raw = $msg->raw ?? [];
        $fromAddress = $raw['from']['emailAddress']['address']
            ?? $raw['sender']['emailAddress']['address']
            ?? '';
        $fromName = $raw['from']['emailAddress']['name']
            ?? $raw['sender']['emailAddress']['name']
            ?? null;
        $toAddresses = implode(', ', array_map(
            fn ($r) => $r['emailAddress']['address'] ?? '',
            $raw['toRecipients'] ?? [],
        ));
        $ccAddresses = implode(', ', array_map(
            fn ($r) => $r['emailAddress']['address'] ?? '',
            $raw['ccRecipients'] ?? [],
        ));

        $now = now();

        // 1) Mail-Session anlegen (oder existierende verwenden).
        $sessionId = $existingSession?->id;
        if (!$sessionId) {
            $sessionId = DB::table('user_connector_mail_sessions')->insertGetId([
                'connection_id' => $connectionId,
                'connector_key' => 'microsoft365',
                'external_mail_id' => $mailId,
                'conversation_id' => $raw['conversationId'] ?? null,
                'direction' => 'inbound',
                'status' => $raw['isRead'] ?? false ? 'read' : 'new',
                'from_address' => $fromAddress,
                'from_name' => $fromName,
                'to_addresses' => $toAddresses ?: null,
                'cc_addresses' => $ccAddresses ?: null,
                'subject' => $raw['subject'] ?? $msg->subject,
                'body_preview' => $raw['bodyPreview'] ?? null,
                'is_read' => (bool) ($raw['isRead'] ?? false),
                'has_attachments' => (bool) ($raw['hasAttachments'] ?? false),
                'is_draft' => (bool) ($raw['isDraft'] ?? false),
                'received_at' => $raw['receivedDateTime'] ?? null,
                'sent_at' => $raw['sentDateTime'] ?? null,
                'meta' => json_encode([
                    'body' => $raw['body']['content'] ?? null,
                    'bodyContentType' => $raw['body']['contentType'] ?? null,
                    'imported_via' => 'inbox.items.import_from_outlook.POST',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 2) Inbox-Item anlegen.
        $itemId = DB::table('inbox_items')->insertGetId([
            'uuid' => (string) UuidV7::generate(),
            'team_id' => $teamId,
            'user_id' => $context->user->id,
            'source_type' => 'user_connector_mail_session',
            'source_id' => $sessionId,
            'channel' => Channel::Mail->value,
            'sender_identifier' => $this->normaliseEmail($fromAddress),
            'sender_kind' => 'email',
            'sender_label' => $fromName,
            'subject' => $raw['subject'] ?? $msg->subject,
            'preview' => $raw['bodyPreview'] ?? null,
            'body' => $raw['body']['content'] ?? null,
            'body_format' => ($raw['body']['contentType'] ?? 'text') === 'html' ? 'html' : 'text',
            'direction' => 'inbound',
            'status' => $status,
            'received_at' => $raw['receivedDateTime'] ?? $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ToolResult::success([
            'inbox_item_id' => (int) $itemId,
            'mail_session_id' => (int) $sessionId,
            'duplicate' => false,
            'subject' => $raw['subject'] ?? $msg->subject,
            'from' => $fromAddress,
            'received_at' => $raw['receivedDateTime'] ?? null,
            'message' => 'Mail importiert — als Inbox-Item #' . $itemId . ' verfügbar.',
        ]);
    }

    protected function normaliseEmail(?string $email): ?string
    {
        if (!$email) {
            return null;
        }
        return strtolower(trim($email));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'import', 'mail', 'outlook', 'microsoft365', 'historical'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
