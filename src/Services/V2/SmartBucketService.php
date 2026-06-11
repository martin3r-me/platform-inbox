<?php

namespace Platform\Inbox\Services\V2;

use Illuminate\Database\Eloquent\Builder;
use Platform\Inbox\Models\InboxItem;

/**
 * Smart buckets for the V2 inbox sidebar. Each bucket is a function that
 * scopes a query — used both to produce live counts and to constrain the
 * stream when the bucket is active.
 *
 * Buckets are mutually exclusive in display but not in nature: an item can
 * legitimately match "heute" and "vip" and "wartet". The counts reflect the
 * full membership; the active bucket is the filter the user has selected.
 *
 * Keep the bucket list short (max 6 live + snoozed + done) — adding more
 * dilutes the focus the user just told us they want.
 */
class SmartBucketService
{
    public const HEUTE = 'heute';
    public const WARTET = 'wartet';
    public const VIP = 'vip';
    public const PROJEKTE = 'projekte';
    public const NEU = 'neu';
    public const SNOOZED = 'snoozed';
    public const DONE = 'done';

    public const DEFAULT_BUCKET = self::HEUTE;

    /**
     * Buckets in display order. Labels are German because that's the working
     * language of this inbox; icons map to heroicon names used in blades.
     *
     * @return array<int, array{key: string, label: string, icon: string, group: string}>
     */
    public function definitions(): array
    {
        return [
            ['key' => self::HEUTE,    'label' => 'Heute',    'icon' => 'fire',         'group' => 'live'],
            ['key' => self::WARTET,   'label' => 'Wartet',   'icon' => 'clock',        'group' => 'live'],
            ['key' => self::VIP,      'label' => 'VIP',      'icon' => 'star',         'group' => 'live'],
            ['key' => self::PROJEKTE, 'label' => 'Projekte', 'icon' => 'link',         'group' => 'live'],
            ['key' => self::NEU,      'label' => 'Neu',      'icon' => 'hand-raised',  'group' => 'live'],
            ['key' => self::SNOOZED,  'label' => 'Snoozed',  'icon' => 'moon',         'group' => 'archive'],
            ['key' => self::DONE,     'label' => 'Done',     'icon' => 'check-circle', 'group' => 'archive'],
        ];
    }

    /**
     * Live counts keyed by bucket key. Single query per bucket — fine for the
     * 7-bucket list; if we grow past ~12 buckets, switch to a single grouped
     * query or a materialised summary.
     *
     * @return array<string, int>
     */
    public function counts(int $userId): array
    {
        $out = [];
        foreach ($this->definitions() as $def) {
            $out[$def['key']] = $this->apply(
                InboxItem::query()->where('user_id', $userId),
                $def['key'],
                $userId,
            )->count();
        }
        return $out;
    }

    /**
     * Scope a base query down to a bucket. The user_id is passed explicitly
     * because some buckets (projekte) read auxiliary tables that we want to
     * scope on the same identity.
     */
    public function apply(Builder $query, string $bucket, int $userId): Builder
    {
        return match ($bucket) {
            self::HEUTE => $query
                ->where('status', 'new')
                ->where('received_at', '>=', now()->startOfDay()),

            self::WARTET => $query
                ->whereIn('status', ['new', 'snoozed'])
                ->whereNotNull('awaiting_reply_since')
                ->where('awaiting_reply_since', '<=', now()->subDays(2)),

            self::VIP => $query
                ->where('status', 'new')
                ->whereIn(
                    'sender_identifier',
                    fn ($sub) => $sub->select('sender_identifier')
                        ->from('inbox_sender_subscriptions')
                        ->where('user_id', $userId)
                        ->where('is_vip', true),
                )
                ->orWhere(function ($q) {
                    $q->where('status', 'new')->where('importance_score', '>=', 20);
                }),

            self::PROJEKTE => $query
                ->where('status', 'new')
                ->whereIn(
                    'id',
                    fn ($sub) => $sub->select('inbox_item_id')
                        ->from('inbox_item_participants')
                        ->whereNotNull('entity_id'),
                ),

            self::NEU => $query
                ->where('status', 'new')
                ->whereNotIn(
                    'sender_identifier',
                    fn ($sub) => $sub->select('sender_identifier')
                        ->from('inbox_items')
                        ->where('user_id', $userId)
                        ->where('received_at', '<', now()->subDays(60))
                        ->groupBy('sender_identifier'),
                ),

            self::SNOOZED => $query->where('status', 'snoozed'),
            self::DONE => $query->where('status', 'done'),

            default => $query->where('status', 'new'),
        };
    }
}
