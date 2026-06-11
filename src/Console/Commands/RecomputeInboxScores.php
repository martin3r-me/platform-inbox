<?php

namespace Platform\Inbox\Console\Commands;

use Illuminate\Console\Command;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Services\V2\ImportanceScorer;
use Platform\Inbox\Services\V2\SenderPulseUpdater;

/**
 * Walks recent inbox_items, recomputes importance_score, then refreshes the
 * inbox_sender_pulse cache for every unique (user, sender) it touched.
 *
 * Scheduled hourly. Initial run will lift bulk of historical scores; steady
 * state only re-scores items received or modified in the last day (cheap).
 */
class RecomputeInboxScores extends Command
{
    protected $signature = 'inbox:recompute-scores
        {--since=24 : Only re-score items received or updated within the last N hours}
        {--all : Re-score every item, regardless of since}
        {--chunk=500 : Chunk size for cursor walk}
        {--dry-run : Compute without persisting}';

    protected $description = 'Recompute V2 importance_score per item + sender pulse cache.';

    public function handle(ImportanceScorer $scorer, SenderPulseUpdater $pulse): int
    {
        $since = (int) $this->option('since');
        $all = (bool) $this->option('all');
        $chunk = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $touchedSenders = [];
        $scoredItems = 0;

        $query = InboxItem::query()->orderBy('id');
        if (!$all) {
            $query->where(function ($q) use ($since) {
                $q->where('received_at', '>=', now()->subHours($since))
                    ->orWhere('updated_at', '>=', now()->subHours($since));
            });
        }

        $query->chunkById($chunk, function ($items) use ($scorer, $dryRun, &$touchedSenders, &$scoredItems) {
            foreach ($items as $item) {
                $score = $scorer->score($item);
                if (!$dryRun) {
                    $item->importance_score = $score;
                    $item->importance_scored_at = now();
                    $item->saveQuietly();
                }
                if ($item->sender_kind && $item->sender_identifier) {
                    $touchedSenders[$item->user_id . '|' . $item->sender_kind . '|' . $item->sender_identifier] = [
                        $item->user_id, $item->sender_kind, $item->sender_identifier,
                    ];
                }
                $scoredItems++;
            }
        });

        if (!$dryRun) {
            foreach ($touchedSenders as [$userId, $kind, $identifier]) {
                $pulse->refresh($userId, $kind, $identifier);
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . sprintf(
            'Scoring abgeschlossen: %d Items, %d Sender-Pulse aktualisiert.',
            $scoredItems,
            count($touchedSenders),
        ));
        return self::SUCCESS;
    }
}
