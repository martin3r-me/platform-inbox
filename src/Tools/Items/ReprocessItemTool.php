<?php

namespace Platform\Inbox\Tools\Items;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemParticipant;
use Platform\Inbox\Services\InboxIngestionService;

/**
 * Schickt ein bestehendes Inbox-Item nachträglich durch die Standard-
 * Post-Processing-Pipeline: Subscriptions, Auto-Link-Regeln, Participants,
 * Default-Enrichment-Dispatch.
 *
 * Use-Cases:
 *   - Items, die per ImportOutlookMailTool ohne vollen Hook-Lauf entstanden
 *   - Backfilled-Items aus der frühen Phase, bei denen damals keine
 *     Anreicherung lief
 *   - Generelles „nochmal sauber durchschleifen" wenn Templates oder
 *     Engines sich geändert haben
 *
 * Reset-Hinweis: Participants werden vor dem Re-Run gelöscht (sonst
 * Duplikate). Bestehende Enrichments + Entity-Links bleiben unangetastet
 * — die neue Anreicherung kommt als zusätzlicher Lauf, das Show-View
 * promotet sie ggf. zur primären.
 */
class ReprocessItemTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.items.reprocess.POST';
    }

    public function getDescription(): string
    {
        return 'Schickt ein bestehendes Inbox-Item nochmal durch die Standard-Post-Processing-Pipeline '
            . '(Subscriptions, Regeln, Participants, Default-Enrichment). Participants werden vor dem '
            . 'Re-Run gelöscht; Enrichments und Entity-Links bleiben.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item_id' => ['type' => 'integer'],
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

        // Participants löschen — sonst entstehen Duplikate, weil
        // createParticipantsForInserts blind einfügt.
        $deletedParticipants = InboxItemParticipant::where('inbox_item_id', $item->id)->delete();

        // Insert-Row-Shape rekonstruieren, wie sie der normale Ingest baut.
        // Inkl. received_at (wird von upsertSubscriptionsForInserts gebraucht).
        $insertRow = [
            'team_id' => $item->team_id,
            'user_id' => $item->user_id,
            'source_type' => $item->source_type,
            'source_id' => $item->source_id,
            'channel' => $item->channel?->value,
            'sender_identifier' => $item->sender_identifier,
            'sender_kind' => $item->sender_kind,
            'sender_label' => $item->sender_label,
            'direction' => $item->direction,
            'received_at' => $item->received_at,
            'subject' => $item->subject,
            'preview' => $item->preview,
            'body' => $item->body,
        ];

        try {
            app(InboxIngestionService::class)->processInsertedItems(
                (string) $item->source_type,
                [$insertRow],
            );
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Reprocess fehlgeschlagen: ' . $e->getMessage());
        }

        return ToolResult::success([
            'item_id' => $item->id,
            'participants_reset' => $deletedParticipants,
            'message' => 'Item neu durch die Pipeline geschleust — Enrichment-Job wurde dispatched.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'reprocess', 'enrichment', 'maintenance'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => false,
        ];
    }
}
