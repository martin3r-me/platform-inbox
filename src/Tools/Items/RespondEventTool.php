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
 * Convenience: respond accept/decline/tentative to a meeting inbox item.
 * Resolves the underlying user_connector_meeting_session and delegates
 * to user-connectors.microsoft365.calendar.respond. Closes the inbox
 * item on success (the calendar reflects the response, so the inbox
 * row has no further purpose).
 */
class RespondEventTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.items.respond_event.POST';
    }

    public function getDescription(): string
    {
        return 'Antwortet auf eine Termin-Einladung im Inbox: response=accept|decline|tentative. '
            . 'Setzt das Outlook-Event auf den richtigen Status (nicht nur das Inbox-Item) und '
            . 'schließt das Item auf done.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item_id' => ['type' => 'integer'],
                'response' => ['type' => 'string', 'enum' => ['accept', 'decline', 'tentative']],
                'comment' => ['type' => 'string'],
                'send_response' => ['type' => 'boolean', 'description' => 'Default: true.'],
                'close_on_send' => ['type' => 'boolean', 'description' => 'Default: true.'],
            ],
            'required' => ['item_id', 'response'],
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

        if ($item->source_type !== 'user_connector_meeting_session') {
            return ToolResult::error('VALIDATION_ERROR', 'respond_event ist nur für Meeting-Items.');
        }

        $session = DB::table('user_connector_meeting_sessions')
            ->where('id', $item->source_id)
            ->first(['id', 'connection_id', 'external_event_id']);
        if (!$session || empty($session->external_event_id)) {
            return ToolResult::error('NOT_FOUND', 'Keine Graph-Event-ID gefunden.');
        }

        try {
            $registry = app(ToolRegistry::class);
            $tool = $registry->get('user-connectors.microsoft365.calendar.respond');
            if (!$tool) {
                return ToolResult::error('NOT_FOUND', 'calendar.respond-Tool nicht registriert.');
            }
            $result = $tool->execute([
                'connection_id' => (int) $session->connection_id,
                'event_id' => (string) $session->external_event_id,
                'response' => (string) $arguments['response'],
                'comment' => isset($arguments['comment']) ? (string) $arguments['comment'] : null,
                'send_response' => (bool) ($arguments['send_response'] ?? true),
            ], $context);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Respond fehlgeschlagen: ' . $e->getMessage());
        }

        if (!$result->success) {
            $err = is_array($result->error ?? null) ? ($result->error['message'] ?? 'fehlgeschlagen') : ($result->error ?? 'fehlgeschlagen');
            return ToolResult::error('EXECUTION_ERROR', (string) $err);
        }

        $closeOnSend = $arguments['close_on_send'] ?? true;
        if ($closeOnSend) {
            $item->update([
                'status' => InboxItemStatus::Done->value,
                'handled_at' => now(),
            ]);
        }

        return ToolResult::success([
            'item_id' => $item->id,
            'response' => $arguments['response'],
            'closed' => (bool) $closeOnSend,
            'message' => 'Termin-Antwort an Outlook gesendet — Inbox-Item ' . ($closeOnSend ? 'auf done.' : 'unverändert.'),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'meeting', 'respond', 'rsvp'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
