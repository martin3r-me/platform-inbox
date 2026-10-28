<?php

namespace Platform\Inbox\Services;

use Platform\Core\Inbox\Contracts\ItemRelations;
use Platform\Core\Inbox\Enums\InboxItemRelation;
use Platform\Inbox\Models\InboxItemLink;

/**
 * Link-Naht des Kerns, implementiert über inbox_item_links. Entscheidet, WELCHE
 * Relationen zu EINER Einheit konsolidieren: supplements (Aufnahme ⊃ Meeting),
 * transcript_of (Transkript ⊃ Quelle) und reply_to (Mail-Kette). `references`
 * bewusst NICHT — ein Verweis ist ein Zeiger, keine Verschmelzung.
 */
class ItemRelationsService implements ItemRelations
{
    protected const CONSOLIDATING = [
        InboxItemRelation::Supplements->value,
        InboxItemRelation::TranscriptOf->value,
        InboxItemRelation::ReplyTo->value,
    ];

    public function relatedIds(int $itemId): array
    {
        $links = InboxItemLink::query()
            ->whereIn('relation', self::CONSOLIDATING)
            ->where(function ($q) use ($itemId) {
                $q->where('from_inbox_item_id', $itemId)
                    ->orWhere('to_inbox_item_id', $itemId);
            })
            ->get(['from_inbox_item_id', 'to_inbox_item_id']);

        $ids = [];
        foreach ($links as $l) {
            $ids[] = (int) $l->from_inbox_item_id;
            $ids[] = (int) $l->to_inbox_item_id;
        }

        return array_values(array_filter(array_unique($ids), fn ($id) => $id !== $itemId));
    }

    public function anyFor(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if (! $itemIds) {
            return [];
        }

        $rows = InboxItemLink::query()
            ->whereIn('relation', self::CONSOLIDATING)
            ->where(function ($q) use ($itemIds) {
                $q->whereIn('from_inbox_item_id', $itemIds)
                    ->orWhereIn('to_inbox_item_id', $itemIds);
            })
            ->get(['from_inbox_item_id', 'to_inbox_item_id']);

        $wanted = array_flip($itemIds);
        $hit = [];
        foreach ($rows as $l) {
            $from = (int) $l->from_inbox_item_id;
            $to = (int) $l->to_inbox_item_id;
            if (isset($wanted[$from])) {
                $hit[$from] = true;
            }
            if (isset($wanted[$to])) {
                $hit[$to] = true;
            }
        }

        return array_map('intval', array_keys($hit));
    }
}
