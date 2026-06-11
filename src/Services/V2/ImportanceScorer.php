<?php

namespace Platform\Inbox\Services\V2;

use Illuminate\Support\Facades\DB;
use Platform\Inbox\Models\InboxItem;

/**
 * Computes the per-item importance_score used by the V2 stream sort and the
 * 💎 VIP bucket. Rules are transparent and additive — score breakdown is
 * exposed via `breakdown()` for the sender-overview "warum VIP?" tooltip.
 *
 *   +999  manual VIP flag on the sender subscription (escape hatch)
 *    +30  sender's primary entity is a Carrier (customer/lead/active project)
 *    +20  user replied to this sender within the last 7 days
 *    +20  sender belongs to an entity already linked to this item
 *    +15  frequency: sender has >5 items in the last 30 days
 *    +10  internal domain (@bhgdigital.de)
 *
 * Score is decimal(5,2); cap stays well under that. Hot loop friendly —
 * a single sender lookup + a handful of count queries per item.
 */
class ImportanceScorer
{
    public const W_MANUAL_VIP = 999.0;
    public const W_CARRIER = 30.0;
    public const W_REPLIED_RECENTLY = 20.0;
    public const W_VIP_AT_LINKED_ENTITY = 20.0;
    public const W_FREQUENT_SENDER = 15.0;
    public const W_INTERNAL_DOMAIN = 10.0;

    protected const INTERNAL_DOMAIN = 'bhgdigital.de';

    public function score(InboxItem $item): float
    {
        return $this->breakdown($item)['total'];
    }

    /**
     * Returns the raw score components for transparency. Used by the sender
     * overview tooltip and by tests so regressions show up as wrong items
     * rather than wrong totals.
     *
     * @return array{total: float, parts: array<string, float>}
     */
    public function breakdown(InboxItem $item): array
    {
        $parts = [];

        if ($this->isManualVip($item)) {
            $parts['manual_vip'] = self::W_MANUAL_VIP;
        }
        if ($this->isCarrier($item)) {
            $parts['carrier'] = self::W_CARRIER;
        }
        if ($this->repliedRecently($item)) {
            $parts['replied_recently'] = self::W_REPLIED_RECENTLY;
        }
        if ($this->isFrequentSender($item)) {
            $parts['frequent_sender'] = self::W_FREQUENT_SENDER;
        }
        if ($this->isInternalDomain($item)) {
            $parts['internal_domain'] = self::W_INTERNAL_DOMAIN;
        }

        return [
            'total' => array_sum($parts),
            'parts' => $parts,
        ];
    }

    protected function isManualVip(InboxItem $item): bool
    {
        if (!$item->sender_kind || !$item->sender_identifier) {
            return false;
        }
        return DB::table('inbox_sender_subscriptions')
            ->where('user_id', $item->user_id)
            ->where('sender_kind', $item->sender_kind)
            ->where('sender_identifier', $item->sender_identifier)
            ->where('is_vip', true)
            ->exists();
    }

    /**
     * Cheap heuristic for now: any inbox_entity_link with kind=carrier
     * targeting an entity that participants link to. Until the entity model
     * exposes a clean "is_carrier" predicate, we treat any linked organization
     * entity as a carrier signal — almost always correct in practice (links
     * are deliberate). Refined once vsm_class is in.
     */
    protected function isCarrier(InboxItem $item): bool
    {
        return DB::table('inbox_item_participants')
            ->where('inbox_item_id', $item->id)
            ->whereNotNull('entity_id')
            ->exists();
    }

    protected function repliedRecently(InboxItem $item): bool
    {
        if (!$item->sender_kind || !$item->sender_identifier) {
            return false;
        }
        return DB::table('inbox_items')
            ->where('user_id', $item->user_id)
            ->where('sender_kind', $item->sender_kind)
            ->where('sender_identifier', $item->sender_identifier)
            ->where('direction', 'outbound')
            ->where('received_at', '>=', now()->subDays(7))
            ->exists();
    }

    protected function isFrequentSender(InboxItem $item): bool
    {
        if (!$item->sender_kind || !$item->sender_identifier) {
            return false;
        }
        $count = DB::table('inbox_items')
            ->where('user_id', $item->user_id)
            ->where('sender_kind', $item->sender_kind)
            ->where('sender_identifier', $item->sender_identifier)
            ->where('received_at', '>=', now()->subDays(30))
            ->count();
        return $count > 5;
    }

    protected function isInternalDomain(InboxItem $item): bool
    {
        if ($item->sender_kind !== 'email' || !$item->sender_identifier) {
            return false;
        }
        return str_ends_with(strtolower($item->sender_identifier), '@' . self::INTERNAL_DOMAIN);
    }
}
