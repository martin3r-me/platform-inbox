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
 * Forward an inbox item through its native provider:
 *   - mail/microsoft365      → user-connectors.microsoft365.mail.forward
 *                              (Graph /forward — preserves attachments)
 *   - message/microsoft365   → user-connectors.microsoft365.teams.chat.forward
 *                              (quote+send into target chat — Teams has no
 *                              native chat forward)
 *
 * Argument shape depends on the channel: mail wants recipient addresses
 * (to[]), Teams wants a target_chat_id. Both close the source item on
 * success unless close_on_send=false.
 */
class ForwardItemTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.items.forward.POST';
    }

    public function getDescription(): string
    {
        return 'Leitet ein Inbox-Item nativ über den Provider weiter. '
            . 'Mail: to[] = Empfänger-Adressen (Graph /forward, behält Anhänge). '
            . 'Teams-Chat: target_chat_id (quote+send in den Ziel-Chat). '
            . 'Optional: comment (Intro-Text), close_on_send (default true).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item_id' => ['type' => 'integer'],
                'to' => [
                    'type' => 'array',
                    'description' => 'Empfänger-Adressen (nur für mail/microsoft365).',
                    'items' => ['type' => 'string'],
                ],
                'target_chat_id' => [
                    'type' => 'string',
                    'description' => 'Ziel-Chat-ID (nur für message/microsoft365 Teams-Chat).',
                ],
                'comment' => ['type' => 'string', 'description' => 'Optionaler Intro-Text.'],
                'close_on_send' => ['type' => 'boolean', 'description' => 'Default: true.'],
            ],
            'required' => ['item_id'],
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

        $comment = (string) ($arguments['comment'] ?? '');
        $closeOnSend = $arguments['close_on_send'] ?? true;
        $registry = app(ToolRegistry::class);

        if ($item->source_type === 'user_connector_mail_session') {
            return $this->forwardMail($item, $arguments, $context, $registry, $comment, (bool) $closeOnSend);
        }

        if ($item->source_type === 'user_connector_message_session') {
            return $this->forwardTeamsChat($item, $arguments, $context, $registry, $comment, (bool) $closeOnSend);
        }

        return ToolResult::error('VALIDATION_ERROR', 'Forward für source_type "' . $item->source_type . '" nicht unterstützt.');
    }

    protected function forwardMail(
        InboxItem $item,
        array $arguments,
        ToolContext $context,
        ToolRegistry $registry,
        string $comment,
        bool $closeOnSend,
    ): ToolResult {
        $session = DB::table('user_connector_mail_sessions')
            ->where('id', $item->source_id)
            ->first(['id', 'connection_id', 'external_mail_id']);
        if (!$session || empty($session->external_mail_id)) {
            return ToolResult::error('NOT_FOUND', 'Keine externe Mail-ID gefunden.');
        }

        $to = array_values(array_filter(array_map(
            fn ($a) => is_string($a) ? trim($a) : null,
            (array) ($arguments['to'] ?? []),
        )));
        if (empty($to)) {
            return ToolResult::error('VALIDATION_ERROR', 'to[] muss mindestens eine Empfänger-Adresse enthalten.');
        }

        $tool = $registry->get('user-connectors.microsoft365.mail.forward');
        if (!$tool) {
            return ToolResult::error('NOT_FOUND', 'mail.forward-Tool nicht registriert.');
        }

        try {
            $result = $tool->execute([
                'connection_id' => (int) $session->connection_id,
                'external_mail_id' => (string) $session->external_mail_id,
                'to' => $to,
                'comment' => $comment,
            ], $context);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Mail-Forward fehlgeschlagen: ' . $e->getMessage());
        }

        if (!$result->success) {
            return $this->fail($result);
        }

        $this->maybeClose($item, $closeOnSend);

        return ToolResult::success([
            'item_id' => $item->id,
            'forwarded' => true,
            'channel' => 'mail',
            'recipients' => $to,
            'closed' => $closeOnSend,
            'message' => 'Mail-Forward über microsoft365 erfolgreich — Anhänge erhalten.',
        ]);
    }

    protected function forwardTeamsChat(
        InboxItem $item,
        array $arguments,
        ToolContext $context,
        ToolRegistry $registry,
        string $comment,
        bool $closeOnSend,
    ): ToolResult {
        $session = DB::table('user_connector_message_sessions')
            ->where('id', $item->source_id)
            ->first(['id', 'connection_id', 'connector_key', 'chat_id', 'external_message_id']);
        if (!$session) {
            return ToolResult::error('NOT_FOUND', 'Message-Session nicht gefunden.');
        }
        if (($session->connector_key ?? '') !== 'microsoft365') {
            return ToolResult::error('VALIDATION_ERROR', 'Teams-Forward derzeit nur für microsoft365.');
        }
        if (empty($session->chat_id) || empty($session->external_message_id)) {
            return ToolResult::error('NOT_FOUND', 'chat_id oder external_message_id fehlt auf der Session.');
        }

        $targetChatId = trim((string) ($arguments['target_chat_id'] ?? ''));
        if ($targetChatId === '') {
            return ToolResult::error('VALIDATION_ERROR', 'target_chat_id ist für Teams-Chat-Forward erforderlich.');
        }

        $tool = $registry->get('user-connectors.microsoft365.teams.chat.forward');
        if (!$tool) {
            return ToolResult::error('NOT_FOUND', 'teams.chat.forward-Tool nicht registriert.');
        }

        try {
            $result = $tool->execute([
                'connection_id' => (int) $session->connection_id,
                'source_chat_id' => (string) $session->chat_id,
                'source_message_id' => (string) $session->external_message_id,
                'target_chat_id' => $targetChatId,
                'comment' => $comment,
            ], $context);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Teams-Forward fehlgeschlagen: ' . $e->getMessage());
        }

        if (!$result->success) {
            return $this->fail($result);
        }

        $this->maybeClose($item, $closeOnSend);

        return ToolResult::success([
            'item_id' => $item->id,
            'forwarded' => true,
            'channel' => 'message',
            'target_chat_id' => $targetChatId,
            'closed' => $closeOnSend,
            'message' => 'Teams-Chat-Forward (quote+send) erfolgreich.',
        ]);
    }

    protected function maybeClose(InboxItem $item, bool $closeOnSend): void
    {
        if (!$closeOnSend) {
            return;
        }
        $item->update([
            'status' => InboxItemStatus::Done->value,
            'handled_at' => now(),
        ]);
    }

    protected function fail(ToolResult $result): ToolResult
    {
        $err = $result->error;
        if (is_array($err)) {
            $err = $err['message'] ?? 'Forward fehlgeschlagen.';
        }
        return ToolResult::error('EXECUTION_ERROR', (string) ($err ?? 'Forward fehlgeschlagen.'));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'forward', 'mail', 'teams', 'microsoft365'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => false,
        ];
    }
}
