<?php

namespace Platform\Inbox\Tools\Items;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemHandoff;
use Platform\Inbox\Services\InboxEntityLinkService;

/**
 * Lists inbox items for dialog-based triage. Defaults to the current user's
 * open items (status=new), latest first. Returns a compact shape with just
 * the fields needed to decide on an action — body + enrichment live in show.
 */
class ListItemsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.items.list.GET';
    }

    public function getDescription(): string
    {
        return 'Listet Inbox-Items des Users (default: status=new, neueste zuerst). '
            . 'Filterbar nach channel (mail|call|message|meeting|recording) und freier Suche. '
            . 'Kompakte Antwort: id, channel, sender, subject, preview, received_at, plus '
            . 'Counts für Entity-Links und vorhandene Handoffs — Body + Enrichment via show.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'channel' => ['type' => 'string', 'description' => 'mail | call | message | meeting | recording'],
                'status' => ['type' => 'string', 'description' => 'new (default) | done | ignored | snoozed | all'],
                'search' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'description' => 'Default 30, max 200.', 'minimum' => 1, 'maximum' => 200],
                'offset' => ['type' => 'integer', 'description' => 'Default 0.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $userId = $context->user->id;
        $limit = (int) ($arguments['limit'] ?? 30);
        $offset = (int) ($arguments['offset'] ?? 0);
        $status = $arguments['status'] ?? 'new';
        $channel = $arguments['channel'] ?? null;
        $search = $arguments['search'] ?? null;

        $query = InboxItem::query()
            ->where('user_id', $userId)
            ->orderByDesc('received_at');

        if ($status !== 'all') {
            $query->where('status', $status);
            if ($status === 'new') {
                $query->where(function ($q) {
                    $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
                });
            }
        }
        if ($channel) {
            $query->where('channel', $channel);
        }
        if ($search) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('subject', 'like', $like)
                    ->orWhere('preview', 'like', $like)
                    ->orWhere('sender_identifier', 'like', $like)
                    ->orWhere('sender_label', 'like', $like);
            });
        }

        $total = (clone $query)->count();
        $items = $query->limit($limit)->offset($offset)->get();
        $ids = $items->pluck('id')->all();

        $linksByItem = empty($ids) ? [] : app(InboxEntityLinkService::class)->linksForItems($ids);
        $handoffsByItem = empty($ids) ? [] : InboxItemHandoff::query()
            ->whereIn('inbox_item_id', $ids)
            ->whereNull('enrichment_id')
            ->whereNull('action_item_index')
            ->get()
            ->groupBy('inbox_item_id')
            ->map(fn ($g) => $g->keyBy('kind')->all())
            ->all();

        return ToolResult::success([
            'total' => $total,
            'count' => $items->count(),
            'items' => $items->map(fn ($i) => [
                'id' => $i->id,
                'channel' => $i->channel?->value,
                'direction' => $i->direction,
                'status' => $i->status?->value,
                'subject' => $i->subject,
                'preview' => $i->preview,
                'sender_label' => $i->sender_label,
                'sender_identifier' => $i->sender_identifier,
                'sender_kind' => $i->sender_kind,
                'received_at' => $i->received_at?->toIso8601String(),
                'snoozed_until' => $i->snoozed_until?->toIso8601String(),
                'entity_links' => array_map(fn ($e) => ['id' => $e['id'], 'name' => $e['name']], $linksByItem[$i->id] ?? []),
                'task_id' => isset($handoffsByItem[$i->id][InboxItemHandoff::KIND_PLANNER_TASK])
                    ? $handoffsByItem[$i->id][InboxItemHandoff::KIND_PLANNER_TASK]->target_id
                    : null,
                'ticket_id' => isset($handoffsByItem[$i->id][InboxItemHandoff::KIND_HELPDESK_TICKET])
                    ? $handoffsByItem[$i->id][InboxItemHandoff::KIND_HELPDESK_TICKET]->target_id
                    : null,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'inspection',
            'tags' => ['inbox', 'items', 'list', 'triage'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'read',
            'idempotent' => true,
        ];
    }
}
