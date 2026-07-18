<?php

namespace Platform\Inbox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B — Backfill der Serien-Identität.
 *
 * 1) Füllt series_master_id/occurrence_type auf Meeting-Sessions aus deren meta
 *    (für Zeilen, deren Enrichment die Felder schon trägt, aber vor Existenz der
 *    Spalten geschrieben wurde).
 * 2) Zieht die Serien-Identität von der Session auf die verknüpften Inbox-Items
 *    nach.
 *
 * Idempotent: aktualisiert nur Zeilen, deren Zielfeld noch NULL ist. Historische
 * Termine ohne seriesMasterId in der meta bleiben unberührt — die bekommen die ID
 * erst bei erneuter Anreicherung über Graph (bewusst nicht Teil dieses Backfills,
 * da Token-/API-intensiv).
 */
class BackfillMeetingSeriesCommand extends Command
{
    protected $signature = 'inbox:backfill-meeting-series';

    protected $description = 'Backfill: Serien-Identität (seriesMasterId) auf Meeting-Sessions und Inbox-Items nachziehen.';

    public function handle(): int
    {
        if (
            ! Schema::hasTable('user_connector_meeting_sessions')
            || ! Schema::hasColumn('user_connector_meeting_sessions', 'series_master_id')
        ) {
            $this->warn('Serienspalten der Meeting-Sessions fehlen — Migrationen zuerst ausführen.');

            return self::SUCCESS;
        }

        $sessionUpdated = $this->backfillSessions();
        $itemsUpdated = $this->backfillInboxItems();

        $this->info("Backfill abgeschlossen: {$sessionUpdated} Sessions, {$itemsUpdated} Inbox-Items aktualisiert.");

        return self::SUCCESS;
    }

    protected function backfillSessions(): int
    {
        $updated = 0;

        DB::table('user_connector_meeting_sessions')
            ->whereNull('series_master_id')
            ->whereNotNull('meta')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$updated) {
                foreach ($rows as $row) {
                    $meta = json_decode($row->meta ?? '', true) ?: [];
                    $seriesMasterId = $meta['seriesMasterId'] ?? null;
                    $occurrenceType = $meta['occurrenceType'] ?? null;

                    if ($seriesMasterId === null && $occurrenceType === null) {
                        continue;
                    }

                    DB::table('user_connector_meeting_sessions')
                        ->where('id', $row->id)
                        ->update([
                            'series_master_id' => $seriesMasterId,
                            'occurrence_type' => $occurrenceType,
                        ]);
                    $updated++;
                }
            });

        return $updated;
    }

    protected function backfillInboxItems(): int
    {
        if (
            ! Schema::hasTable('inbox_items')
            || ! Schema::hasColumn('inbox_items', 'series_master_id')
        ) {
            return 0;
        }

        $updated = 0;

        DB::table('inbox_items as i')
            ->join('user_connector_meeting_sessions as s', 's.id', '=', 'i.source_id')
            ->where('i.source_type', 'user_connector_meeting_session')
            ->whereNull('i.series_master_id')
            ->whereNotNull('s.series_master_id')
            ->select('i.id', 's.series_master_id', 's.occurrence_type')
            ->orderBy('i.id')
            ->chunkById(500, function ($rows) use (&$updated) {
                foreach ($rows as $row) {
                    DB::table('inbox_items')
                        ->where('id', $row->id)
                        ->update([
                            'series_master_id' => $row->series_master_id,
                            'occurrence_type' => $row->occurrence_type,
                        ]);
                    $updated++;
                }
            }, 'i.id', 'id');

        return $updated;
    }
}
