<?php

namespace Platform\Inbox\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Models\InboxItem;
use Platform\Organization\Models\OrganizationTimeEntry;
use Platform\Organization\Services\EntityDimensionBridge;
use Platform\UserConnectors\Models\UserConnectorMeetingSession;

/**
 * Bucht abgeschlossene Termine als echte Ist-Zeit — auf das MEETING.
 *
 * Läuft abends: für Meeting-Inbox-Items, deren Termin tagsüber stattgefunden hat
 * (Session status=completed), wird das zugehörige Meeting aufgelöst (eigene Spalte
 * meeting_id, sonst über iCalUId+Tag) und — sofern das Meeting am Org-Baum hängt —
 * pro (User, Meeting) eine OrganizationTimeEntry mit context_type = Meeting::class
 * angelegt. Der Kontext ist damit das fachliche Meeting (Titel, Agenda), nicht mehr
 * ein Infrastruktur-Objekt (Inbox-Item).
 *
 * Über MeetingsEntityLinkProvider::timeTrackableCascades() rechnet der
 * EntityTimeResolver diese Zeit dem Knoten zu (time_total_minutes). Weil jeder
 * Teilnehmer sein eigenes Inbox-Item hat, entsteht je Person eine eigene Buchung
 * auf dasselbe Meeting — am gemeinsamen Knoten summieren sich die Personenstunden.
 *
 * Idempotent: je (Meeting, User) eine Buchung (metadata.source=meeting_inbox),
 * withTrashed → vom User gelöschte Auto-Buchungen kommen nicht wieder.
 *
 * Migriert außerdem die frühere Phase-A-Buchung weg: alte Einträge mit
 * context_type = InboxItem werden entfernt (die Zeit hängt jetzt am Meeting).
 *
 * Entscheidungen (docs/plans/meetings-inbox-time.md):
 *   - nur status=completed + Start im Fenster
 *   - keine RSVP-Filterung
 *   - is_billed = false
 *   - Meeting muss am Baum hängen (sonst rollt die Zeit nirgends auf)
 */
class MaterializeMeetingTimeCommand extends Command
{
    /** FQCN als String (soft-coupled: keine harte Abhängigkeit aufs meetings-Modul). */
    protected const MEETING_CONTEXT = 'Platform\\Meetings\\Models\\Meeting';

    protected $signature = 'inbox:materialize-meeting-time
        {--date= : Nur diesen Tag verarbeiten (YYYY-MM-DD), sonst Rückblick-Fenster}
        {--days=2 : Rückblick in Tagen ohne --date (Default 2, fängt späte Termine sicher ab)}
        {--dry-run : Nichts schreiben, nur zählen}';

    protected $description = 'Bucht abgeschlossene, am Baum eingehängte Meetings als Ist-Zeit (pro Teilnehmer, auf das Meeting).';

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

        // Migration: alte Phase-A-Buchungen (context_type = InboxItem) entfernen —
        // die Ist-Zeit hängt jetzt am Meeting. Idempotent (soft-delete).
        $legacy = $this->cleanupLegacy($dryRun);

        if ($items->isEmpty()) {
            $this->info('Keine abgeschlossenen Termine im Fenster.');
            $this->reportLegacy($legacy, $dryRun);

            return self::SUCCESS;
        }

        // Termin-Identität → Meeting auflösen (eigene Spalte, sonst iCalUId + Tag).
        $meetingByItem = [];
        foreach ($items as $item) {
            $mid = $this->resolveMeetingId($item, $item->source?->start_at);
            if ($mid) {
                $meetingByItem[$item->id] = $mid;
            }
        }
        $meetingIds = array_values(array_unique($meetingByItem));

        // Nur Meetings, die am Baum hängen (sonst rollt die Zeit nirgends auf).
        $meetingLinks = $meetingIds
            ? EntityDimensionBridge::linksForLinkables(['meeting'], $meetingIds, false)
            : collect();
        $linkedMeetings = $meetingLinks
            ->pluck('linkable_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->flip();

        // Bereits gebuchte Meeting-Zeit je (Meeting, User) — Idempotenz.
        $existing = $meetingIds
            ? OrganizationTimeEntry::query()
                ->withTrashed()
                ->where('context_type', self::MEETING_CONTEXT)
                ->whereIn('context_id', $meetingIds)
                ->where('metadata->source', 'meeting_inbox')
                ->get(['context_id', 'user_id'])
                ->map(fn ($r) => ((int) $r->context_id) . ':' . ((int) $r->user_id))
                ->flip()
            : collect();

        // Für --dry-run: Ziel-Knoten je Meeting + Nutzernamen für die Vorschau.
        $nodesByMeeting = [];
        $userNames = [];
        if ($dryRun) {
            $nodesByMeeting = $this->meetingNodeLabels($meetingLinks);
            $userNames = DB::table('users')
                ->whereIn('id', $items->pluck('user_id')->unique()->all())
                ->pluck('name', 'id')
                ->all();
        }

        $plan = [];
        $created = 0;
        $skippedNoMeeting = 0;
        $skippedUnlinked = 0;
        $skippedExisting = 0;

        foreach ($items as $item) {
            $meetingId = $meetingByItem[$item->id] ?? null;

            if (! $meetingId) {
                $skippedNoMeeting++;
                continue;
            }

            if (! isset($linkedMeetings[$meetingId])) {
                $skippedUnlinked++;
                continue;
            }

            if (isset($existing[$meetingId . ':' . $item->user_id])) {
                $skippedExisting++;
                continue;
            }

            $session = $item->source;
            $minutes = $this->minutesFor($session);

            if ($dryRun) {
                $nodes = collect($nodesByMeeting[$meetingId] ?? [])->implode(', ');
                $plan[] = sprintf(
                    '  • %s — %d Min — %s — %s (#%d) → %s',
                    $session->subject ?: ($item->subject ?: 'Meeting'),
                    $minutes,
                    $session->start_at->toDateString(),
                    $userNames[$item->user_id] ?? 'User',
                    $item->user_id,
                    $nodes !== '' ? $nodes : '—',
                );
            } else {
                OrganizationTimeEntry::create([
                    'team_id' => $item->team_id,
                    'user_id' => $item->user_id,
                    'context_type' => self::MEETING_CONTEXT,
                    'context_id' => $meetingId,
                    'work_date' => $session->start_at->toDateString(),
                    'minutes' => $minutes,
                    'is_billed' => false,
                    'note' => $session->subject ?: $item->subject,
                    'metadata' => [
                        'source' => 'meeting_inbox',
                        'meeting_id' => $meetingId,
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
            '%sMeeting-Zeit: %d gebucht, %d ohne Meeting, %d Meeting nicht am Baum, %d bereits vorhanden.',
            $dryRun ? '[dry-run] ' : '',
            $created,
            $skippedNoMeeting,
            $skippedUnlinked,
            $skippedExisting,
        ));
        $this->reportLegacy($legacy, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Meeting zur Termin-Identität auflösen: eigene Spalte (Promoter), sonst über
     * iCalUId + Tag gegen meetings_meetings (Teilnehmer ohne eigenen Backlink).
     * Soft-coupled via DB::table.
     */
    protected function resolveMeetingId(InboxItem $item, ?Carbon $start): ?int
    {
        if ($item->meeting_id) {
            return (int) $item->meeting_id;
        }

        $icalUid = $item->ical_uid ?: null;
        if (
            ! $icalUid
            || ! Schema::hasTable('meetings_meetings')
            || ! Schema::hasColumn('meetings_meetings', 'ical_uid')
        ) {
            return null;
        }

        $query = DB::table('meetings_meetings')
            ->where('ical_uid', $icalUid)
            ->whereNull('deleted_at');

        // Serie: mehrere Meetings teilen die iCalUId (eins je Vorkommen) → Tag wählen.
        if ($start) {
            $query->whereDate('start_date', Carbon::parse($start)->toDateString());
        }

        $id = $query->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * Ziel-Knoten je Meeting (nur für die Dry-Run-Vorschau): dimension_value → Entity.
     *
     * @param  \Illuminate\Support\Collection  $meetingLinks  Links aus linksForLinkables(['meeting'], …)
     * @return array<int, array<int, string>>  [meetingId => [Knotenname, …]]
     */
    protected function meetingNodeLabels($meetingLinks): array
    {
        if ($meetingLinks->isEmpty()) {
            return [];
        }

        // entity_id direkt vom Link (wie MeetingPromotionService) → Entity-Name.
        $entityIds = $meetingLinks->pluck('entity_id')->filter()->unique()->all();
        $names = empty($entityIds)
            ? []
            : DB::table('organization_entities')
                ->whereIn('id', $entityIds)
                ->pluck('name', 'id')
                ->all();

        $byMeeting = [];
        foreach ($meetingLinks as $link) {
            $entityId = $link->entity_id ?? null;
            if ($entityId && isset($names[$entityId])) {
                $byMeeting[(int) $link->linkable_id][] = $names[$entityId];
            }
        }

        return $byMeeting;
    }

    /**
     * Alte Phase-A-Buchungen (context_type = InboxItem, source = meeting_inbox)
     * entfernen — die Ist-Zeit hängt jetzt am Meeting. Soft-delete, idempotent.
     *
     * @return int Anzahl (im Dry-Run: würde entfernen).
     */
    protected function cleanupLegacy(bool $dryRun): int
    {
        $query = OrganizationTimeEntry::query()
            ->where('context_type', InboxItem::class)
            ->where('metadata->source', 'meeting_inbox');

        if ($dryRun) {
            return (clone $query)->count();
        }

        return $query->delete();
    }

    protected function reportLegacy(int $n, bool $dryRun): void
    {
        if ($n <= 0) {
            return;
        }

        $this->info(sprintf(
            '%sAlte Inbox-Buchungen (Phase A) %s: %d.',
            $dryRun ? '[dry-run] ' : '',
            $dryRun ? 'würden entfernt' : 'entfernt',
            $n,
        ));
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
