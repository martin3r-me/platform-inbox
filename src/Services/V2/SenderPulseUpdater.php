<?php

namespace Platform\Inbox\Services\V2;

use Illuminate\Support\Facades\DB;
use Platform\Inbox\Models\InboxSenderPulse;

/**
 * Rebuilds the inbox_sender_pulse cache for a single (user, sender) pair:
 *   pulse_14d        — {YYYY-MM-DD: count} histogram for the sparkline
 *   importance_score — max(item.importance_score) across last 30d
 *   channel_mix      — fraction per channel across last 30d
 *   last_seen_at     — most recent received_at
 *
 * Called from the hourly recompute job and on demand when an item enters
 * the inbox (so the stream card reflects new activity within seconds).
 */
class SenderPulseUpdater
{
    public function refresh(int $userId, string $senderKind, string $senderIdentifier): void
    {
        $since14 = now()->subDays(14)->startOfDay();
        $since30 = now()->subDays(30)->startOfDay();

        // 14-day histogram. Sparse keys are fine — the renderer fills missing
        // days with 0 so the sparkline always shows the full window.
        $rows = DB::table('inbox_items')
            ->selectRaw('DATE(received_at) as d, COUNT(*) as c')
            ->where('user_id', $userId)
            ->where('sender_kind', $senderKind)
            ->where('sender_identifier', $senderIdentifier)
            ->where('received_at', '>=', $since14)
            ->groupBy('d')
            ->pluck('c', 'd')
            ->all();
        $pulse = [];
        foreach ($rows as $d => $c) {
            $pulse[(string) $d] = (int) $c;
        }

        // Channel mix over 30d as fractions (sum to 1). Drives the
        // "Teams 70% · Mail 30%" bar in the sender overview.
        $channelCounts = DB::table('inbox_items')
            ->selectRaw('channel, COUNT(*) as c')
            ->where('user_id', $userId)
            ->where('sender_kind', $senderKind)
            ->where('sender_identifier', $senderIdentifier)
            ->where('received_at', '>=', $since30)
            ->groupBy('channel')
            ->pluck('c', 'channel')
            ->all();
        $total = array_sum($channelCounts) ?: 1;
        $channelMix = [];
        foreach ($channelCounts as $channel => $count) {
            $channelMix[(string) $channel] = round($count / $total, 3);
        }

        $aggregates = DB::table('inbox_items')
            ->selectRaw('MAX(importance_score) as max_score, MAX(received_at) as last_seen')
            ->where('user_id', $userId)
            ->where('sender_kind', $senderKind)
            ->where('sender_identifier', $senderIdentifier)
            ->where('received_at', '>=', $since30)
            ->first();
        $maxScore = (float) ($aggregates->max_score ?? 0);
        $lastSeen = $aggregates->last_seen ?? null;

        InboxSenderPulse::updateOrCreate(
            [
                'user_id' => $userId,
                'sender_kind' => $senderKind,
                'sender_identifier' => $senderIdentifier,
            ],
            [
                'pulse_14d' => $pulse,
                'channel_mix' => $channelMix,
                'importance_score' => $maxScore,
                'last_seen_at' => $lastSeen,
                'refreshed_at' => now(),
            ],
        );
    }
}
