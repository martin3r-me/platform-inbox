<?php

namespace Platform\Inbox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Enums\InboxItemRelation;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Services\InboxItemLinkService;
use Platform\Inbox\Services\InboxRecordingCorrelator;

/**
 * Sweep: hängt noch nicht verlinkte Aufnahmen ans passende Meeting (Zeit-Overlap).
 * Fängt den Out-of-Order-Fall ab — Aufnahme ist vor dem Kalender-Sync da (oder der
 * On-Ingest-Versuch fand das Meeting noch nicht). Eindeutiger Overlap → Auto-Link;
 * mehrdeutig/keins → für die Vorschlags-UI liegen lassen.
 */
class CorrelateRecordingsCommand extends Command
{
    protected $signature = 'inbox:correlate-recordings {--days=3 : Rückblick in Tagen} {--dry-run}';

    protected $description = 'Verlinkt offene Aufnahmen mit dem passenden Meeting (eindeutiger Zeit-Overlap → auto).';

    public function handle(InboxRecordingCorrelator $correlator, InboxItemLinkService $links): int
    {
        if (! Schema::hasTable('inbox_items')) {
            $this->warn('inbox_items fehlt — übersprungen.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $from = now()->subDays((int) $this->option('days'));

        $recordings = InboxItem::query()
            ->where('channel', Channel::Recording->value)
            ->where('received_at', '>=', $from)
            ->get()
            ->filter(fn (InboxItem $r) => $links->outgoing($r->id, InboxItemRelation::Supplements)->isEmpty());

        $linked = 0;
        $ambiguous = 0;
        $none = 0;

        foreach ($recordings as $recording) {
            $count = $correlator->candidatesFor($recording)->count();

            if ($count === 1) {
                if (! $dryRun) {
                    $correlator->correlate($recording);
                }
                $linked++;
            } elseif ($count > 1) {
                $ambiguous++;
            } else {
                $none++;
            }
        }

        $this->info(sprintf(
            '%sAufnahmen: %d verlinkt, %d mehrdeutig (→ Vorschlag), %d ohne Meeting.',
            $dryRun ? '[dry-run] ' : '',
            $linked,
            $ambiguous,
            $none,
        ));

        return self::SUCCESS;
    }
}
