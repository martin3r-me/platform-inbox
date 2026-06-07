<?php

namespace Platform\Inbox\Tools\Items;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Tools\ToolRegistry;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;

/**
 * Native forward of a mail inbox item — resolves the underlying
 * user_connector_mail_session.external_mail_id and delegates to
 * user-connectors.microsoft365.mail.forward, which calls MS Graph's
 * /forward endpoint. Attachments + original body are preserved.
 *
 * Currently mail/microsoft365 only — other channels (Teams chat, SMS,
 * recordings) don't have a meaningful "forward" semantic at the
 * provider level.
 */
class ForwardItemTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.items.forward.POST';
    }

    public function getDescription(): string
    {
        return 'Leitet ein Inbox-Item nativ über den Provider weiter (aktuell nur mail/microsoft365). '
            . 'Original-Anhänge und -Body bleiben erhalten. Optional: comment (Intro-Text), close_on_send (default true).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item_id' => ['type' => 'integer'],
                'to' => [
                    'type' => 'array',
                    'description' => 'Empfänger-Adressen.',
                    'items' => ['type' => 'string'],
                ],
                'comment' => ['type' => 'string', 'description' => 'Optionaler Intro-Text vor dem Original.'],
                'close_on_send' => ['type' => 'boolean', 'description' => 'Default: true.'],
            ],
            'required' => ['item_id', 'to'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $item = InboxItem::query()
            ->where('id', (int) ($arguments['item_id'] ?? 0))
            ->where('user_id', $context->user->id)
            ->first();
        if (!$item) {
            return ToolResult::error('NOT_FOUND', 'Item nicht gefunden oder kein Zugriff.');
        }

        // Forward is currently mail-only — other channels lack a Graph-style forward.
        if ($item->source_type !== 'user_connector_mail_session') {
            return ToolResult::error('VALIDATION_ERROR', 'Forward ist aktuell nur für mail/microsoft365-Items unterstützt.');
        }

        $session = DB::table('user_connector_mail_sessions')
            ->where('id', $item->source_id)
            ->first(['id', 'connection_id', 'external_mail_id']);
        if (!$session || empty($session->external_mail_id)) {
            return ToolResult::error('NOT_FOUND', 'Keine externe Mail-ID gefunden — Original-Session nicht mehr im user-connectors-Cache.');
        }

        $to = array_values(array_filter(array_map(
            fn ($a) => is_string($a) ? trim($a) : null,
            (array) ($arguments['to'] ?? []),
        )));
        if (empty($to)) {
            return ToolResult::error('VALIDATION_ERROR', 'to muss mindestens eine Adresse enthalten.');
        }

        $comment = (string) ($arguments['comment'] ?? '');
        $closeOnSend = $arguments['close_on_send'] ?? true;

        try {
            $registry = app(ToolRegistry::class);
            $forwardTool = $registry->get('user-connectors.microsoft365.mail.forward');
            if (!$forwardTool) {
                return ToolResult::error('NOT_FOUND', 'user-connectors.microsoft365.mail.forward-Tool nicht registriert.');
            }
            $result = $forwardTool->execute([
                'connection_id' => (int) $session->connection_id,
                'external_mail_id' => (string) $session->external_mail_id,
                'to' => $to,
                'comment' => $comment,
            ], $context);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Forward fehlgeschlagen: ' . $e->getMessage());
        }

        if (!$result->success) {
            return ToolResult::error('EXECUTION_ERROR', $result->error ?? 'Forward fehlgeschlagen.');
        }

        if ($closeOnSend) {
            $item->update([
                'status' => InboxItemStatus::Done->value,
                'handled_at' => now(),
            ]);
        }

        return ToolResult::success([
            'item_id' => $item->id,
            'forwarded' => true,
            'recipients' => $to,
            'closed' => (bool) $closeOnSend,
            'message' => 'Forward über microsoft365 erfolgreich — Original-Anhänge erhalten.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'forward', 'mail', 'microsoft365'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => false,
        ];
    }
}
