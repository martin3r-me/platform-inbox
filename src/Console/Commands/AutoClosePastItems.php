<?php

namespace Platform\Inbox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Housekeeping: drain the persistent backlog that comes from items which
 * carry no real triage decision.
 *
 *   Meeting-Items → the "action" is accept/decline via the calendar itself.
 *     Once the meeting time has passed there's nothing left to triage.
 *   Missed calls → returned or forgotten within the week. Anything older
 *     is functionally irrelevant.
 *
 * Runs hourly (see InboxServiceProvider schedule).
 */
class AutoClosePastItems extends Command
{
    protected $signature = 'inbox:auto-close
        {--meetings-grace-hours=4 : Zeitpuffer nach Meeting-Ende bevor auto-closed wird}
        {--call-stale-days=7 : Missed Calls dieser Alterklasse werden auto-closed}
        {--dry-run : Nur zählen, nichts ändern}';

    protected $description = 'Schließt Meeting-Items nach Ende + Missed Calls nach N Tagen automatisch.';

    public function handle(): int
    {
        $meetingsGraceHours = max(0, (int) $this->option('meetings-grace-hours'));
        $callStaleDays = max(1, (int) $this->option('call-stale-days'));
        $dryRun = (bool) $this->option('dry-run');

        $meetingCutoff = now()->subHours($meetingsGraceHours);
        $callCutoff = now()->subDays($callStaleDays);

        // Meetings: received_at speichert bei Meeting-Sessions den start_at.
        // Nach start_at + grace als done markieren.
        $meetingQuery = DB::table('inbox_items')
            ->where('channel', 'meeting')
            ->where('status', 'new')
            ->where('received_at', '<', $meetingCutoff);

        // Missed calls: alles was seit N Tagen als "new" liegt.
        // (Direction-agnostisch — outbound-Calls sollten via skip_outbound
        // beim Ingest ohnehin nicht landen.)
        $callQuery = DB::table('inbox_items')
            ->where('channel', 'call')
            ->where('status', 'new')
            ->where('received_at', '<', $callCutoff);

        $meetingCount = (clone $meetingQuery)->count();
        $callCount = (clone $callQuery)->count();

        if ($dryRun) {
            $this->info('Meetings die geschlossen würden: ' . $meetingCount);
            $this->info('Calls die geschlossen würden: ' . $callCount);
            return self::SUCCESS;
        }

        $meetingUpdated = $meetingQuery->update([
            'status' => 'done',
            'handled_at' => now(),
            'updated_at' => now(),
        ]);
        $callUpdated = $callQuery->update([
            'status' => 'done',
            'handled_at' => now(),
            'updated_at' => now(),
        ]);

        $this->table(
            ['Channel', 'Cutoff', 'Closed'],
            [
                ['meeting', $meetingCutoff->toDateTimeString(), $meetingUpdated],
                ['call',    $callCutoff->toDateTimeString(),    $callUpdated],
            ],
        );

        return self::SUCCESS;
    }
}
