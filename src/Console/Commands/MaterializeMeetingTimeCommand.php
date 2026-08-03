<?php

namespace Platform\Inbox\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Services\InboxEntityLinkService;
use Platform\Organization\Models\OrganizationTimeEntry;
use Platform\Organization\Services\EntityDimensionBridge;
use Platform\UserConnectors\Models\UserConnectorMeetingSession;

/**
 * Bucht abgeschlossene Termine als echte Ist-Zeit.
 *
 * Läuft abends: für Meeting-Inbox-Items, deren Termin tagsüber stattgefunden hat
 * (Session status=completed) UND die tatsächlich am Baum eingehängt sind (morph
 * inbox_item über organization_dimension_links), wird pro (User, Termin) eine
 * OrganizationTimeEntry mit context_type = InboxItem::class angelegt.
 *
 * Über InboxEntityLinkProvider::timeTrackableCascades() rechnet der
 * EntityTimeResolver diese Zeit dem Knoten zu (time_total_minutes). Weil jeder
 * Teilnehmer sein eigenes Inbox-Item hat, summieren sich am gemeinsamen Knoten
 * die Personenstunden von selbst.
 *
 * Idempotent: bereits gebuchte Einträge (metadata.source=meeting_inbox) werden
 * übersprungen — Re-Runs doppeln nicht.
 *
 * Entscheidungen (docs/plans/meetings-inbox-time.md):
 *   - nur status=completed + Start im Fenster
 *   - keine RSVP-Filterung in v1
 *   - is_billed = false
 *   - kein Overlap-Schutz gegen manuelle Buchungen in v1
 */
class MaterializeMeetingTimeCommand extends Command
{
    protected $signature = 'inbox:materialize-meeting-time
        {--date= : Nur diesen Tag verarbeiten (YYYY-MM-DD), sonst Rückblick-Fenster}
        {--days=2 : Rückblick in Tagen ohne --date (Default 2, fängt späte Termine sicher ab)}
        {--dry-run : Nichts schreiben, nur zählen}';

    protected $description = 'Bucht abgeschlossene, am Baum eingehängte Termine als Ist-Zeit (Meeting-Zeit).';

    public function handle(): int
    {
        if (
            ! class_exists(OrganizationTimeEntry::class)
            || ! Schema::hasTable('organization_time_entries')
            || ! Schema::hasTable('organization_dimension_links')
        ) {
            $this->warn('Organization-Modul nicht verfügbar — übersprungen.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        [$from, $to] = $this->resolveWindow();

        // Kandidaten: Meeting-Inbox-Items im Fenster (received_at = Termin-Start
        // laut Ingestion-Mapping → index-freundlicher Vorfilter).
        $items = InboxItem::query()
            ->where('channel', Channel::Meeting->value)
            ->whereBetween('received_at', [$from, $to])
            ->with('source')
            ->get()
            ->filter(function (InboxItem $item) use ($from, $to): bool {
                $session = $item->source;

                return $session instanceof UserConnectorMeetingSession
                    && $session->status === 'completed'
                    && $session->start_at !== null
                    && $session->start_at->between($from, $to)
                    && $this->minutesFor($session) > 0;
            })
            ->values();

        if ($items->isEmpty()) {
            $this->info('Keine abgeschlossenen Termine im Fenster.');

            return self::SUCCESS;
        }

        $itemIds = $items->pluck('id')->all();

        // Nur tatsächlich eingehängte Items (am Knoten verlinkt).
        $linkedIds = EntityDimensionBridge::linksForLinkables(['inbox_item'], $itemIds, false)
            ->pluck('linkable_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->flip();

        // Bereits gebuchte Meeting-Zeit (Idempotenz). withTrashed(), damit vom
        // User gelöschte Auto-Buchungen nicht jeden Abend wieder auftauchen.
        $existingIds = OrganizationTimeEntry::query()
            ->withTrashed()
            ->where('context_type', InboxItem::class)
            ->whereIn('context_id', $itemIds)
            ->where('metadata->source', 'meeting_inbox')
            ->pluck('context_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->flip();

        // Für --dry-run: Ziel-Knoten je Item + Nutzernamen auflösen, damit sichtbar
        // wird, WAS wohin gebucht würde (die TimeEntry hängt am InboxItem und läuft
        // über den EntityTimeResolver auf diesen Knoten auf).
        $nodesByItem = [];
        $userNames = [];
        if ($dryRun) {
            try {
                $nodesByItem = app(InboxEntityLinkService::class)->linksForItems($itemIds);
            } catch (\Throwable $e) {
                $nodesByItem = [];
            }
            $userNames = DB::table('users')
                ->whereIn('id', $items->pluck('user_id')->unique()->all())
                ->pluck('name', 'id')
                ->all();
        }

        $plan = [];
        $created = 0;
        $skippedUnlinked = 0;
        $skippedExisting = 0;

        foreach ($items as $item) {
            if (! isset($linkedIds[$item->id])) {
                $skippedUnlinked++;
                continue;
            }

            if (isset($existingIds[$item->id])) {
                $skippedExisting++;
                continue;
            }

            $session = $item->source;
            $minutes = $this->minutesFor($session);

            if ($dryRun) {
                $nodes = collect($nodesByItem[$item->id] ?? [])
                    ->map(fn ($n) => ($n['path'] ?? null) ? $n['path'] . ' › ' . $n['name'] : ($n['name'] ?? '—'))
                    ->implode(', ');
                $plan[] = sprintf(
                    '  • %s — %d Min — %s — %s (#%d) → %s',
                    $session->subject ?: ($item->subject ?: 'Meeting'),
                    $minutes,
                    $session->start_at->toDateString(),
                    $userNames[$item->user_id] ?? 'User',
                    $item->user_id,
                    $nodes !== '' ? $nodes : '—',
                );
            }

            if (! $dryRun) {
                OrganizationTimeEntry::create([
                    'team_id' => $item->team_id,
                    'user_id' => $item->user_id,
                    'context_type' => InboxItem::class,
                    'context_id' => $item->id,
                    'work_date' => $session->start_at->toDateString(),
                    'minutes' => $minutes,
                    'is_billed' => false,
                    'note' => $session->subject ?: $item->subject,
                    'metadata' => [
                        'source' => 'meeting_inbox',
                        'inbox_item_id' => $item->id,
                        'external_event_id' => $session->external_event_id,
                    ],
                ]);
            }

            $created++;
        }

        if ($dryRun && ! empty($plan)) {
            $this->line('[dry-run] Würde buchen (Termin — Dauer — Datum — Person → Knoten):');
            foreach ($plan as $line) {
                $this->line($line);
            }
        }

        $this->info(sprintf(
            '%sMeeting-Zeit: %d gebucht, %d ohne Baum-Link übersprungen, %d bereits vorhanden.',
            $dryRun ? '[dry-run] ' : '',
            $created,
            $skippedUnlinked,
            $skippedExisting,
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveWindow(): array
    {
        if ($this->option('date')) {
            $day = Carbon::parse($this->option('date'));

            return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
        }

        $days = max(1, (int) $this->option('days'));

        return [now()->copy()->subDays($days)->startOfDay(), now()];
    }

    protected function minutesFor(UserConnectorMeetingSession $session): int
    {
        if ($session->duration_minutes !== null) {
            return (int) $session->duration_minutes;
        }

        if ($session->start_at && $session->end_at) {
            return (int) $session->start_at->diffInMinutes($session->end_at);
        }

        return 0;
    }
}
