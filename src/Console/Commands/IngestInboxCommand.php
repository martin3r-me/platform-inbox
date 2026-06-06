<?php

namespace Platform\Inbox\Console\Commands;

use Illuminate\Console\Command;
use Platform\Inbox\Services\InboxIngestionService;

class IngestInboxCommand extends Command
{
    protected $signature = 'inbox:ingest {--minutes=60 : look back window in minutes}';
    protected $description = 'Materialize recent user-connector sessions into inbox_items for the connection owners.';

    public function handle(InboxIngestionService $service): int
    {
        $minutes = (int) $this->option('minutes');
        $created = $service->ingestRecent($minutes);
        $this->info("Created {$created} inbox items (looking back {$minutes} minutes).");

        return self::SUCCESS;
    }
}
