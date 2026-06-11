<?php

namespace Platform\Inbox\Services\V2;

use Illuminate\Support\Collection;
use Platform\Inbox\Models\InboxItem;
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
