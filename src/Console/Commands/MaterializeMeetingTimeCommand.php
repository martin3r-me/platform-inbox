<?php

namespace Platform\Inbox\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Inbox\Enums\Channel;
use Platform\Core\Inbox\Models\InboxItem;
use Platform\Organization\Models\OrganizationTimeEntry;
use Platform\Organization\Services\EntityDimensionBridge;
use Platform\UserConnectors\Models\UserConnectorMeetingSession;

/**
 * Bucht abgeschlossene Termine als echte Ist-Zeit — auf das INBOX-ITEM.
 *
 * „Termin = Inbox-Item" (Phase 2 der Meetings-Auflösung): es gibt kein separates
 * Meeting-Objekt mehr. Für Meeting-Inbox-Items, deren Termin tagsüber stattfand
 * (Session status=completed) UND die am Org-Baum hängen (morph `inbox_item`), wird
 * pro Teilnehmer-Item eine OrganizationTimeEntry mit context_type = InboxItem
 * angelegt. Der EntityTimeResolver rollt sie über
 * InboxEntityLinkProvider::timeTrackableCascades() auf den Knoten. Weil jeder
 * Teilnehmer sein eigenes Item + eigene Buchung hat, summieren sich die
 * Personenstunden am Knoten — kein Doppelzählen (der alte `meeting`-Morph fällt mit
 * dem Modul weg).
 *
 * Verantwortungstrennung: Dieses Command BUCHT nur. Die VERTEILUNG des Knoten-Links
 * über Serie/Teilnehmer (gleiche ical_uid/series_master_id) macht
 * `inbox:inherit-node-links` — hängt EIN Vorkommen am Knoten, erben alle. Darum
 * reicht hier das Gate „Item hängt am Knoten": neue Vorkommen werden von inherit
 * verlinkt und dann hier gebucht (das --days-Fenster fängt die Verzögerung ab).
 *
 * Idempotent: je Item eine Buchung (metadata.source=meeting_inbox), withTrashed →
 * vom User gelöschte Auto-Buchungen kommen nicht wieder.
 *
 * Historische Meeting-Buchungen (context_type=Meeting) migriert das einmalige
 * `inbox:migrate-meeting-time-to-item` — NICHT dieser Tageslauf.
 */
class MaterializeMeetingTimeCommand extends Command
{
    /** Zeit-Kontext = das Inbox-Item (muss zum InboxEntityLinkProvider-Cascade passen). */
    protected const INBOX_CONTEXT = InboxItem::class;

    protected $signature = 'inbox:materialize-meeting-time
        {--date= : Nur diesen Tag verarbeiten (YYYY-MM-DD), sonst Rückblick-Fenster}
        {--days=2 : Rückblick in Tagen ohne --date (Default 2, fängt späte Termine sicher ab)}
        {--dry-run : Nichts schreiben, nur zählen}';

    protected $description = 'Bucht abgeschlossene, am Baum eingehängte Termine als Ist-Zeit (pro Teilnehmer, auf das Inbox-Item).';

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

        $itemIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Nur Items, die am Baum hängen (eigener inbox_item-Link; die Verteilung über
        // Serie/Teilnehmer besorgt inbox:inherit-node-links).
        $links = EntityDimensionBridge::linksForLinkables(['inbox_item'], $itemIds, false);
        $linkedItems = [];
        $entitiesByItem = [];
        foreach ($links as $link) {
            $iid = (int) $link->linkable_id;
            $linkedItems[$iid] = true;
            $eid = (int) ($link->entity_id ?? 0);
            if ($eid > 0) {
                $entitiesByItem[$iid][$eid] = true;
            }
        }

        // Bereits gebuchte Item-Zeit — Idempotenz (je Item eine Buchung).
        $existing = OrganizationTimeEntry::query()
            ->withTrashed()
            ->where('context_type', self::INBOX_CONTEXT)
            ->whereIn('context_id', $itemIds)
            ->where('metadata->source', 'meeting_inbox')
            ->pluck('context_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        $userNames = [];
        $entityNames = [];
        if ($dryRun) {
            $userNames = DB::table('users')
                ->whereIn('id', $items->pluck('user_id')->unique()->all())
                ->pluck('name', 'id')
                ->all();
            $allEntityIds = collect($entitiesByItem)->flatMap(fn ($m) => array_keys($m))->unique()->all();
            $entityNames = empty($allEntityIds)
                ? []
                : DB::table('organization_entities')->whereIn('id', $allEntityIds)->pluck('name', 'id')->all();
        }

        $plan = [];
        $created = 0;
        $skippedUnlinked = 0;
        $skippedExisting = 0;

        foreach ($items as $item) {
            $iid = (int) $item->id;

            if (! isset($linkedItems[$iid])) {
                $skippedUnlinked++;
                continue;
            }

            if (isset($existing[$iid])) {
                $skippedExisting++;
                continue;
            }

            $session = $item->source;
            $minutes = $this->minutesFor($session);

            if ($dryRun) {
                $nodes = collect(array_keys($entitiesByItem[$iid] ?? []))
                    ->map(fn ($eid) => $entityNames[$eid] ?? ('#' . $eid))
                    ->implode(', ');
                $plan[] = sprintf(
                    '  • %s — %d Min — %s — %s (#%d) → %s',
                    $session->subject ?: ($item->subject ?: 'Termin'),
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
                    'context_type' => self::INBOX_CONTEXT,
                    'context_id' => $iid,
                    'work_date' => $session->start_at->toDateString(),
                    'minutes' => $minutes,
                    'is_billed' => false,
                    'note' => $session->subject ?: $item->subject,
                    'metadata' => [
                        'source' => 'meeting_inbox',
                        'inbox_item_id' => $iid,
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
            '%sTermin-Zeit: %d gebucht, %d nicht am Baum, %d bereits vorhanden.',
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
