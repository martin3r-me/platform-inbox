<?php

namespace Platform\Inbox\Console\Commands;

use Illuminate\Console\Command;
use Platform\Inbox\Services\InboxIngestionService;

class IngestInboxCommand extends Command
{
    protected $signature = 'inbox:ingest
        {--minutes=60 : look back window in minutes}
        {--loop : keep ingesting in 2000-row chunks until nothing new arrives (for backfills)}
        {--max-rounds=20 : safety cap on loop iterations}';
    protected $description = 'Materialize user-connector sessions into inbox_items. Default: last 60 min. With --loop: backfill in chunks until the entire window is covered.';

    public function handle(InboxIngestionService $service): int
    {
        $minutes = (int) $this->option('minutes');
        $loop = (bool) $this->option('loop');
        $maxRounds = (int) $this->option('max-rounds');

        if (!$loop) {
            $created = $service->ingestRecent($minutes);
            $this->info("Created {$created} inbox items (looking back {$minutes} minutes).");
            return self::SUCCESS;
        }

        $total = 0;
        for ($round = 1; $round <= $maxRounds; $round++) {
            $created = $service->ingestRecent($minutes);
            $total += $created;
            $this->info("Round {$round}: {$created} new items.");
            if ($created === 0) {
                break;
            }
        }
        $this->info("Backfill done. Total: {$total} items across {$round} round(s), window {$minutes} min.");

        return self::SUCCESS;
    }
}
