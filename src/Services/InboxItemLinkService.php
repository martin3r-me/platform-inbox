<?php

namespace Platform\Inbox\Services;

use Illuminate\Database\Eloquent\Collection;
use Platform\Inbox\Contracts\InboxItemLinkContract;
use Platform\Inbox\Enums\InboxItemRelation;
use Platform\Inbox\Models\InboxItemLink;

/**
 * Default implementation of {@see InboxItemLinkContract}.
 *
 * Idempotency: link() reuses an existing row when the (from, to, relation)
 * triple already exists, merging meta keys instead of failing on the
 * unique index. Producers can fire the same link call multiple times
 * (e.g. on retried jobs) without duplicate rows or exceptions.
 */
class InboxItemLinkService implements InboxItemLinkContract
{
    public function link(
        int $fromInboxItemId,
        int $toInboxItemId,
        InboxItemRelation $relation,
        array $meta = []
    ): InboxItemLink {
        $existing = InboxItemLink::query()
            ->where('from_inbox_item_id', $fromInboxItemId)
            ->where('to_inbox_item_id', $toInboxItemId)
            ->where('relation', $relation->value)
            ->first();

        if ($existing) {
            if (!empty($meta)) {
                $merged = array_merge($existing->meta ?? [], $meta);
                $existing->update(['meta' => $merged]);
            }
            return $existing;
        }

        return InboxItemLink::create([
            'from_inbox_item_id' => $fromInboxItemId,
            'to_inbox_item_id' => $toInboxItemId,
            'relation' => $relation->value,
            'meta' => $meta !== [] ? $meta : null,
        ]);
    }

    public function supplements(
        int $supplementaryItemId,
        int $primaryItemId,
        array $meta = []
    ): InboxItemLink {
        return $this->link(
            $supplementaryItemId,
            $primaryItemId,
            InboxItemRelation::Supplements,
            $meta
        );
    }

    public function outgoing(int $inboxItemId, ?InboxItemRelation $relation = null): Collection
    {
        $q = InboxItemLink::query()->where('from_inbox_item_id', $inboxItemId);
        if ($relation) {
            $q->where('relation', $relation->value);
        }
        return $q->get();
    }

    public function incoming(int $inboxItemId, ?InboxItemRelation $relation = null): Collection
    {
        $q = InboxItemLink::query()->where('to_inbox_item_id', $inboxItemId);
        if ($relation) {
            $q->where('relation', $relation->value);
        }
        return $q->get();
    }

    public function supplementaryFor(int $primaryItemId): Collection
    {
        return $this->incoming($primaryItemId, InboxItemRelation::Supplements);
    }

    public function unlink(int $fromInboxItemId, int $toInboxItemId, InboxItemRelation $relation): void
    {
        InboxItemLink::query()
            ->where('from_inbox_item_id', $fromInboxItemId)
            ->where('to_inbox_item_id', $toInboxItemId)
            ->where('relation', $relation->value)
            ->delete();
    }
}
