<?php

namespace Platform\Inbox\Console\Commands;

use Illuminate\Console\Command;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Services\V2\ThreadResolver;

/**
 * One-shot backfill for the V2 thread_key column. Walks all existing
 * inbox_items, looks up the source row's conversation/chat id, and writes
 * it back. Idempotent — safe to re-run; only items with NULL thread_key
 * (and a thread-bearing channel) are touched.
 */
class BackfillInboxThreadKeys extends Command
{
    protected $signature = 'inbox:backfill-thread-keys
        {--chunk=500 : Chunk size for the cursor walk}
        {--dry-run : Show counts without writing}';

    protected $description = 'Fill the V2 thread_key column on existing inbox_items by reading the source mail/message session.';

    public function handle(ThreadResolver $resolver): int
    {
        $chunk = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $matchedSources = ['user_connector_mail_session', 'user_connector_message_session'];

        $touched = 0;
        $skipped = 0;

        InboxItem::query()
            ->whereNull('thread_key')
            ->whereIn('source_type', $matchedSources)
            ->orderBy('id')
            ->chunkById($chunk, function ($items) use ($resolver, $dryRun, &$touched, &$skipped) {
                foreach ($items as $item) {
                    $key = $resolver->for($item);
                    if ($key === null) {
                        $skipped++;
                        continue;
                    }
                    if (!$dryRun) {
                        $item->thread_key = $key;
                        $item->saveQuietly();
                    }
                    $touched++;
                }
            });

        $this->info(($dryRun ? '[dry-run] ' : '') . "Thread-Keys gefüllt: {$touched}, übersprungen (kein Source-Row): {$skipped}");
        return self::SUCCESS;
    }
}
