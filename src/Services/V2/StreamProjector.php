<?php

namespace Platform\Inbox\Services\V2;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemEnrichment;
use Platform\Inbox\Models\InboxSenderPulse;

/**
 * Projects the smart-bucketed inbox into the sender × thread shape the V2
 * stream renders: each row is a Sender, each Sender carries its Threads,
 * each Thread carries the latest item that represents it.
 *
 * Sorting: smart-first by default — awaiting-reply, then today, then by
 * importance, then by recency. Toggleable to plain chronological via the
 * Shift+S keymap (passed as `sort = 'chronological'`).
 */
class StreamProjector
{
    public function __construct(
        protected SmartBucketService $buckets,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters  optional: ['channel' => 'mail', 'sender' => '…', 'entity' => 17]
     * @return array<int, array{
     *     sender_kind: string,
     *     sender_identifier: string,
     *     sender_label: string,
     *     score: float,
     *     pulse_14d: array<string, int>,
     *     channel_mix: array<string, float>,
     *     last_at: string|null,
     *     awaiting: bool,
     *     threads: array<int, array{
     *         thread_key: string|null,
     *         channel: string,
     *         subject: string|null,
     *         preview: string|null,
     *         received_at: string|null,
     *         item_count: int,
     *         latest_item_id: int,
     *         awaiting: bool,
     *     }>,
     * }>
     */
    public function project(int $userId, string $bucket, array $filters = [], string $sort = 'smart'): array
    {
        $query = InboxItem::query()->where('user_id', $userId);
        $query = $this->buckets->apply($query, $bucket, $userId);

        if (!empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }
        if (!empty($filters['sender'])) {
            $query->where('sender_identifier', $filters['sender']);
        }
        if (!empty($filters['entity'])) {
            $query->whereIn(
                'id',
                fn ($sub) => $sub->select('inbox_item_id')
                    ->from('inbox_item_participants')
                    ->where('entity_id', $filters['entity']),
            );
        }

        // Pull a generous slice — folding will collapse heavily, the stream
        // will only render top N senders anyway. Cap protects against
        // pathological inboxes.
        $items = $query
            ->orderByDesc('received_at')
            ->limit(2000)
            ->get();

        $folded = $this->foldSenderAndThread($items);
        $pulses = $this->loadPulses($userId, $folded);

        $rows = [];
        foreach ($folded as $row) {
            $key = $row['sender_kind'] . '|' . $row['sender_identifier'];
            $pulse = $pulses[$key] ?? null;
            $rows[] = [
                'sender_kind' => $row['sender_kind'],
                'sender_identifier' => $row['sender_identifier'],
                'sender_label' => $row['sender_label'],
                'score' => $pulse?->importance_score ? (float) $pulse->importance_score : $row['max_score'],
                'pulse_14d' => $pulse?->pulse_14d ?? [],
                'channel_mix' => $pulse?->channel_mix ?? [],
                'last_at' => $row['last_at'],
                'awaiting' => $row['awaiting'],
                'threads' => $row['threads'],
            ];
        }

        return $this->sortRows($rows, $sort);
    }

    /**
     * Flat item projection — one row per InboxItem (no sender folding), grouped
     * by a date bucket so the view can render headers like
     * "Demnächst / Heute / Gestern / Diese Woche / Älter". Used by the V2
     * stream when the user wants a chronological item list rather than the
     * sender→thread fold.
     *
     * Items where received_at lies in the future (typical for meetings, whose
     * received_at = meeting start_at) are placed in their own "Demnächst"
     * bucket and sorted ASC inside it so the next event comes first.
     * Everything else sorts DESC (newest first).
     *
     * @param  array<string, mixed>  $filters  optional: ['channel' => 'mail', 'awaiting' => true, 'today' => true]
     * @return array{
     *     groups: array<int, array{key: string, label: string, items: array<int, array<string, mixed>>}>,
     *     total: int,
     *     counts: array<string, int>
     * }
     */
    public function projectItems(int $userId, string $bucket, array $filters = []): array
    {
        $query = InboxItem::query()->where('user_id', $userId);
        $query = $this->buckets->apply($query, $bucket, $userId);

        if (!empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }
        if (!empty($filters['awaiting'])) {
            $query->whereNotNull('awaiting_reply_since');
        }
        if (!empty($filters['today'])) {
            $query->where('received_at', '>=', now()->startOfDay());
        }

        $items = $query
            ->orderByDesc('received_at')
            ->limit(2000)
            ->get();

        // Stream-wide counters drive the quick-filter chips (Heute / Awaiting /
        // Meetings) — computed in PHP so the user sees them update instantly
        // when filters/bucket change without a second query roundtrip.
        $today = now()->startOfDay();
        $counts = [
            'total' => $items->count(),
            'today' => $items->filter(fn ($i) => $i->received_at && $i->received_at->gte($today))->count(),
            'awaiting' => $items->filter(fn ($i) => $i->awaiting_reply_since !== null)->count(),
            'meeting' => $items->filter(fn ($i) => ($i->channel?->value ?? $i->channel) === 'meeting')->count(),
        ];

        // Batched enrichment lookup — one round-trip for the whole page instead
        // of N. We need two slices: the primary done enrichment (to lift its
        // tldr/headline into the stream preview) and any pending/running rows
        // so the stream can show a "wird angereichert"-Indikator instead of a
        // plain item that looks un-enriched while a job is still in flight.
        $enrichmentMap = $this->loadEnrichmentLookup($items->pluck('id')->all());

        $groups = $this->groupByDateBucket($items, $enrichmentMap);

        return [
            'groups' => $groups,
            'total' => $items->count(),
            'counts' => $counts,
        ];
    }

    /**
     * Bulk-load enrichment data for a slice of item ids. Returns a map keyed
     * by item id with two slots:
     *   - 'primary' → ['tldr', 'headline'] from the done is_primary row
     *   - 'status'  → 'done' | 'running' | 'pending' | 'failed' | null
     *
     * Status reflects the *most recent* enrichment row: if a primary done
     * exists we mark it 'done' even when an older failure is in the table;
     * if nothing is done yet we surface running/pending so the UI can render
     * a "wird angereichert" hint.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, array{primary: ?array, status: ?string}>
     */
    protected function loadEnrichmentLookup(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $rows = DB::table('inbox_item_enrichments')
            ->whereIn('inbox_item_id', $itemIds)
            ->select('inbox_item_id', 'status', 'is_primary', 'output', 'run_at')
            ->orderBy('inbox_item_id')
            ->orderByDesc('run_at')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $id = (int) $row->inbox_item_id;
            if (!isset($map[$id])) {
                $map[$id] = ['primary' => null, 'status' => null];
            }
            if ((int) $row->is_primary === 1 && $row->status === InboxItemEnrichment::STATUS_DONE && $map[$id]['primary'] === null) {
                $output = $row->output ? json_decode($row->output, true) : [];
                $map[$id]['primary'] = [
                    'tldr' => $output['tldr'] ?? null,
                    'headline' => $output['headline'] ?? null,
                ];
            }
            // First (most recent) row's status wins unless we already locked
            // in 'done' through a primary above.
            if ($map[$id]['status'] === null) {
                $map[$id]['status'] = $row->status;
            }
        }

        // If a primary done row exists, the status is 'done' even if a newer
        // re-run is pending — that way the user sees the existing summary in
        // the stream while the background job refreshes it.
        foreach ($map as $id => &$slot) {
            if ($slot['primary'] !== null) {
                $slot['status'] = InboxItemEnrichment::STATUS_DONE;
            }
        }
        unset($slot);

        return $map;
    }

    /**
     * Build the chronological day-bucket groups.
     *
     * @param  Collection<int, InboxItem>  $items
     * @param  array<int, array{primary: ?array, status: ?string}>  $enrichmentMap
     * @return array<int, array{key: string, label: string, items: array<int, array<string, mixed>>}>
     */
    protected function groupByDateBucket(Collection $items, array $enrichmentMap = []): array
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $yesterday = $today->subDay();
        $weekStart = $today->subDays(7);
        $monthStart = $today->subDays(30);

        $defs = [
            ['key' => 'upcoming', 'label' => 'Demnächst'],
            ['key' => 'today', 'label' => 'Heute'],
            ['key' => 'yesterday', 'label' => 'Gestern'],
            ['key' => 'week', 'label' => 'Letzte 7 Tage'],
            ['key' => 'month', 'label' => 'Letzte 30 Tage'],
            ['key' => 'older', 'label' => 'Älter'],
        ];
        $buckets = array_fill_keys(array_column($defs, 'key'), []);

        foreach ($items as $item) {
            $rec = $item->received_at;
            $enrichment = $enrichmentMap[$item->id] ?? ['primary' => null, 'status' => null];
            if (!$rec) {
                $buckets['older'][] = $this->shapeItem($item, $enrichment);
                continue;
            }
            $key = match (true) {
                $rec->gt($now)             => 'upcoming',
                $rec->gte($today)          => 'today',
                $rec->gte($yesterday)      => 'yesterday',
                $rec->gte($weekStart)      => 'week',
                $rec->gte($monthStart)     => 'month',
                default                    => 'older',
            };
            $buckets[$key][] = $this->shapeItem($item, $enrichment);
        }

        // Upcoming reads naturally ASC (next event first); the rest stays DESC
        // (newest first) as the query already returned them.
        usort($buckets['upcoming'], fn ($a, $b) => strcmp($a['received_at'] ?? '', $b['received_at'] ?? ''));

        $out = [];
        foreach ($defs as $def) {
            $rows = $buckets[$def['key']];
            if (empty($rows)) {
                continue;
            }
            $out[] = [
                'key' => $def['key'],
                'label' => $def['label'],
                'items' => $rows,
            ];
        }
        return $out;
    }

    /**
     * Shape an InboxItem into the array the view + selection layer use.
     * Includes the keys needed by V2/Inbox::selectItem() to round-trip into the
     * existing sender|thread-based cockpit selection without further lookups.
     *
     * The optional $enrichment slot lifts the primary tldr/headline into the
     * row so the stream can render the LLM summary as the preview override
     * instead of the often-noisy raw body_preview, and marks the row as
     * enriched/in-flight so the view can draw the ✨ chip + skeleton state.
     *
     * @param  array{primary: ?array, status: ?string}  $enrichment
     * @return array<string, mixed>
     */
    protected function shapeItem(InboxItem $item, array $enrichment = ['primary' => null, 'status' => null]): array
    {
        $channel = $item->channel?->value ?? (string) $item->channel;
        $kind = $item->sender_kind ?? 'unknown';
        $id = $item->sender_identifier ?? '';
        $threadKey = $item->thread_key ?: ('item-' . $item->id);

        $primary = $enrichment['primary'] ?? null;
        $status = $enrichment['status'] ?? null;
        $enrichedPreview = $primary['tldr'] ?? $primary['headline'] ?? null;

        return [
            'id' => $item->id,
            'channel' => $channel,
            'subject' => $item->subject,
            'preview' => $item->preview,
            'received_at' => $item->received_at?->toIso8601String(),
            'direction' => $item->direction,
            'awaiting' => $item->awaiting_reply_since !== null,
            'sender_label' => $item->sender_label ?? $id,
            'sender_identifier' => $id,
            'sender_kind' => $kind,
            'sender_key' => $kind . '|' . $id,
            'thread_key' => $threadKey,
            'importance_score' => (float) ($item->importance_score ?? 0),
            'enriched' => $primary !== null,
            'enriched_preview' => $enrichedPreview,
            'enrichment_status' => $status, // 'done' | 'running' | 'pending' | 'failed' | null
        ];
    }

    /**
     * @param  Collection<int, InboxItem>  $items
     * @return array<int, array{
     *     sender_kind: string,
     *     sender_identifier: string,
     *     sender_label: string,
     *     max_score: float,
     *     last_at: string|null,
     *     awaiting: bool,
     *     threads: array<int, array<string, mixed>>,
     * }>
     */
    protected function foldSenderAndThread(Collection $items): array
    {
        $bySender = [];

        foreach ($items as $item) {
            $kind = $item->sender_kind ?? 'unknown';
            $id = $item->sender_identifier ?? '';
            $senderKey = $kind . '|' . $id;

            if (!isset($bySender[$senderKey])) {
                $bySender[$senderKey] = [
                    'sender_kind' => $kind,
                    'sender_identifier' => $id,
                    'sender_label' => $item->sender_label ?? $id,
                    'max_score' => 0.0,
                    'last_at' => null,
                    'awaiting' => false,
                    '_threads' => [],
                ];
            }

            // Channels without a thread_key behave one-item-one-thread; we use
            // the item id stringified as a synthetic key so the renderer can
            // treat all rows uniformly.
            $threadKey = $item->thread_key ?: ('item-' . $item->id);

            if (!isset($bySender[$senderKey]['_threads'][$threadKey])) {
                $bySender[$senderKey]['_threads'][$threadKey] = [
                    'thread_key' => $item->thread_key,
                    'channel' => $item->channel?->value ?? (string) $item->channel,
                    'subject' => $item->subject,
                    'preview' => $item->preview,
                    'received_at' => $item->received_at?->toIso8601String(),
                    'item_count' => 0,
                    'latest_item_id' => $item->id,
                    'awaiting' => false,
                ];
            }

            $thread = &$bySender[$senderKey]['_threads'][$threadKey];
            $thread['item_count']++;
            if ($item->awaiting_reply_since) {
                $thread['awaiting'] = true;
                $bySender[$senderKey]['awaiting'] = true;
            }

            $score = (float) ($item->importance_score ?? 0);
            if ($score > $bySender[$senderKey]['max_score']) {
                $bySender[$senderKey]['max_score'] = $score;
            }

            $lastAt = $item->received_at?->toIso8601String();
            if ($lastAt && ($bySender[$senderKey]['last_at'] === null || $lastAt > $bySender[$senderKey]['last_at'])) {
                $bySender[$senderKey]['last_at'] = $lastAt;
            }

            unset($thread);
        }

        // Flatten threads keyed by thread_key into an indexed list, sorted by
        // received_at desc per sender.
        $out = [];
        foreach ($bySender as $row) {
            $threads = array_values($row['_threads']);
            usort($threads, fn ($a, $b) => strcmp($b['received_at'] ?? '', $a['received_at'] ?? ''));
            unset($row['_threads']);
            $row['threads'] = $threads;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Bulk-load sender pulses for the senders we just folded. Keyed by
     * kind|identifier for O(1) lookup back in project().
     *
     * @param  array<int, array<string, mixed>>  $folded
     * @return array<string, InboxSenderPulse>
     */
    protected function loadPulses(int $userId, array $folded): array
    {
        $keys = [];
        foreach ($folded as $row) {
            $keys[] = [$row['sender_kind'], $row['sender_identifier']];
        }
        if (empty($keys)) {
            return [];
        }

        $query = InboxSenderPulse::query()->where('user_id', $userId);
        $query->where(function ($q) use ($keys) {
            foreach ($keys as [$kind, $identifier]) {
                $q->orWhere(function ($qq) use ($kind, $identifier) {
                    $qq->where('sender_kind', $kind)
                        ->where('sender_identifier', $identifier);
                });
            }
        });

        $out = [];
        foreach ($query->get() as $pulse) {
            $out[$pulse->sender_kind . '|' . $pulse->sender_identifier] = $pulse;
        }
        return $out;
    }

    /**
     * Smart-first sort: awaiting reply, then today's new items, then by
     * importance score, then by recency. Plain 'chronological' falls back to
     * last_at desc.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function sortRows(array $rows, string $sort): array
    {
        if ($sort === 'chronological') {
            usort($rows, fn ($a, $b) => strcmp($b['last_at'] ?? '', $a['last_at'] ?? ''));
            return $rows;
        }

        $today = now()->startOfDay()->toIso8601String();
        usort($rows, function ($a, $b) use ($today) {
            // 1. awaiting first
            if ($a['awaiting'] !== $b['awaiting']) {
                return $a['awaiting'] ? -1 : 1;
            }
            // 2. today before older
            $aToday = ($a['last_at'] ?? '') >= $today;
            $bToday = ($b['last_at'] ?? '') >= $today;
            if ($aToday !== $bToday) {
                return $aToday ? -1 : 1;
            }
            // 3. score
            if ($a['score'] != $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            // 4. last_at desc
            return strcmp($b['last_at'] ?? '', $a['last_at'] ?? '');
        });
        return $rows;
    }
}
