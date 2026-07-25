<?php

namespace Platform\Inbox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kopiert die Gruppen-Identität von den Connector-Sessions auf die Inbox-Items —
 * damit Altdaten aufleuchten (Increment 3):
 *   - Mail:    conversation_id  (Session hat sie schon immer → sofort)
 *   - Meeting: ical_uid / series_master_id / occurrence_type (soweit die Session
 *              sie trägt; iCalUId der Alt-Sessions kommt erst per Graph-Re-Enrich)
 *
 * Idempotent: füllt nur NULL-Felder. Läuft geplant (Catch-up), kein manuelles artisan nötig.
 */
class BackfillIdentityCommand extends Command
{
    protected $signature = 'inbox:backfill-identity {--dry-run}';

    protected $description = 'Kopiert Thread-/Serien-Identität (conversation_id, ical_uid, …) von den Sessions auf die Inbox-Items.';

    public function handle(): int
    {
        if (! Schema::hasTable('inbox_items')) {
            $this->warn('inbox_items fehlt — übersprungen.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $total = 0;

        // Mail-Thread: conversation_id ← Mail-Session.
        $total += $this->copy(
            dry: $dry,
            sessionTable: 'user_connector_mail_sessions',
            sourceType: 'user_connector_mail_session',
            columns: ['conversation_id'],
        );

        // Termin-Serie: ical_uid / series_master_id / occurrence_type ← Meeting-Session.
        $total += $this->copy(
            dry: $dry,
            sessionTable: 'user_connector_meeting_sessions',
            sourceType: 'user_connector_meeting_session',
            columns: ['ical_uid', 'series_master_id', 'occurrence_type'],
        );

        $this->info(sprintf('%sIdentität backfilled: %d Felder.', $dry ? '[dry-run] ' : '', $total));

        return self::SUCCESS;
    }

    /** @param array<int,string> $columns */
    protected function copy(bool $dry, string $sessionTable, string $sourceType, array $columns): int
    {
        if (! Schema::hasTable($sessionTable)) {
            return 0;
        }

        $count = 0;
        foreach ($columns as $col) {
            if (! Schema::hasColumn('inbox_items', $col) || ! Schema::hasColumn($sessionTable, $col)) {
                continue;
            }

            $query = DB::table('inbox_items')
                ->join("{$sessionTable} as s", 's.id', '=', 'inbox_items.source_id')
                ->where('inbox_items.source_type', $sourceType)
                ->whereNull("inbox_items.{$col}")
                ->whereNotNull("s.{$col}");

            $count += $dry
                ? $query->count()
                : $query->update(["inbox_items.{$col}" => DB::raw("s.{$col}")]);
        }

        return $count;
    }
}
