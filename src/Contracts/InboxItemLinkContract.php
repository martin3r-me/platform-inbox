<?php

namespace Platform\Inbox\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Platform\Inbox\Enums\InboxItemRelation;
use Platform\Inbox\Models\InboxItemLink;

/**
 * Public contract for cross-module InboxItem relations.
 *
 * Any module that produces an InboxItem and wants to associate it with
 * another item (typically created by a different producer module) goes
 * through this interface. The Inbox module owns the link table, the
 * relation enum, and the de-duplication logic — consumers don't touch
 * the storage layer directly.
 *
 * Example — Whisper recording supplements a calendar meeting:
 *
 *     $links->supplements(
 *         supplementaryItemId: $recordingItem->id,
 *         primaryItemId: $meetingItem->id,
 *     );
 */
interface InboxItemLinkContract
{
    /**
     * Generic link. Creates the row if (from, to, relation) is new; if
     * the same triple already exists, returns the existing row and
     * merges any meta keys into it.
     */
    public function link(
        int $fromInboxItemId,
        int $toInboxItemId,
        InboxItemRelation $relation,
        array $meta = []
    ): InboxItemLink;

    /**
     * Convenience for the most common case: `$supplementaryItemId`
     * supplements `$primaryItemId`. Equivalent to calling `link()` with
     * `InboxItemRelation::Supplements`.
     */
    public function supplements(
        int $supplementaryItemId,
        int $primaryItemId,
        array $meta = []
    ): InboxItemLink;

    /**
     * All links where the given item is the `from` side. Optional filter
     * narrows to one relation kind.
     */
    public function outgoing(int $inboxItemId, ?InboxItemRelation $relation = null): Collection;

    /**
     * All links where the given item is the `to` side. Optional filter
     * narrows to one relation kind.
     */
    public function incoming(int $inboxItemId, ?InboxItemRelation $relation = null): Collection;

    /**
     * Convenience: every supplementary InboxItem hanging off a given
     * primary item (e.g. all recordings that supplement a meeting).
     */
    public function supplementaryFor(int $primaryItemId): Collection;

    /**
     * Removes the (from, to, relation) link if it exists. Idempotent —
     * returns silently when no such link is found.
     */
    public function unlink(int $fromInboxItemId, int $toInboxItemId, InboxItemRelation $relation): void;
}
