<?php

namespace Platform\Inbox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Platform\Inbox\Models\InboxItem;
use Platform\Organization\Models\OrganizationTimeEntry;
use Platform\Organization\Services\EntityDimensionBridge;

/**
 * EINMALIGE Migration (Phase 2 der Meetings-Auflösung): historische Ist-Zeit vom
 * Meeting-Kontext auf das Inbox-Item umhängen — exakt und faithful.
 *
 * Ausgangslage: alte Buchungen context_type=Meeting, metadata.source=meeting_inbox,
 * metadata.inbox_item_id = das Teilnehmer-Item, context_id/metadata.meeting_id = Meeting.
 * Das Meeting hing am Knoten (morph `meeting`) — nur verlinkte Meetings wurden je
 * gebucht, also hat jede Buchung ein verortetes Meeting.
 *
 * Diese Migration:
 *   1. legt je Meeting-Buchung eine 1:1-Buchung context_type=InboxItem,
 *      context_id = metadata.inbox_item_id an (gleiche Minuten/Datum/User/Team),
 *   2. hängt dasselbe Inbox-Item an exakt die Knoten, an denen das Meeting hing
 *      (morph `inbox_item`), damit der Resolver dieselbe Zeit an denselben Knoten
 *      rollt — nach dem Cascade-Flip (inbox_item an, meeting weg) deckungsgleich.
 *
 * Idempotent: existiert die Item-Buchung schon (gleiche source, withTrashed), wird
 * übersprungen. createLink ist firstOrCreate. Default lässt die Alt-Buchungen stehen
 * (schadet nicht — der meeting-Cascade ist aus, rollt nichts doppelt); --prune
 * soft-löscht die migrierten Meeting-Buchungen.
 */
class MigrateMeetingTimeToItemCommand extends Command
{
    protected const MEETING_CONTEXT = 'Platform\\Meetings\\Models\\Meeting';
    protected const INBOX_CONTEXT = InboxItem::class;

    protected $signature = 'inbox:migrate-meeting-time-to-item
        {--dry-run : Nichts schreiben, nur zählen}
        {--prune : Migrierte Meeting-Buchungen danach soft-löschen}';

    protected $description = 'Einmalig: historische Meeting-Ist-Zeit (context_type=Meeting) auf das Inbox-Item umhängen.';

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
        $prune = (bool) $this->option('prune');

        // Alte Meeting-Buchungen (aktiv; vom User gelöschte NICHT wiederbeleben).
        $legacy = OrganizationTimeEntry::query()
            ->where('context_type', self::MEETING_CONTEXT)
            ->where('metadata->source', 'meeting_inbox')
            ->get();

        if ($legacy->isEmpty()) {
            $this->info('Keine historischen Meeting-Buchungen gefunden — nichts zu migrieren.');

            return self::SUCCESS;
        }

        // Meeting → Knoten (dieselbe Verortung, die der Resolver bisher nutzte).
        $meetingIds = $legacy
            ->map(fn ($e) => (int) $e->context_id)
            ->unique()
            ->values()
            ->all();

        $meetingLinks = EntityDimensionBridge::linksForLinkables(['meeting'], $meetingIds, false);
        $entitiesByMeeting = [];
        foreach ($meetingLinks as $link) {
            $entityId = (int) ($link->entity_id ?? 0);
            if ($entityId > 0) {
                $entitiesByMeeting[(int) $link->linkable_id][$entityId] = true;
            }
        }

        // Schon migrierte Item-Buchungen (Idempotenz, withTrashed).
        $itemIds = $legacy
            ->map(fn ($e) => (int) (is_array($e->metadata) ? ($e->metadata['inbox_item_id'] ?? 0) : 0))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $alreadyMigrated = empty($itemIds)
            ? collect()
            : OrganizationTimeEntry::query()
                ->withTrashed()
                ->where('context_type', self::INBOX_CONTEXT)
                ->whereIn('context_id', $itemIds)
                ->where('metadata->source', 'meeting_inbox')
                ->pluck('context_id')
                ->map(fn ($id) => (int) $id)
                ->flip();

        $migrated = 0;
        $skippedExisting = 0;
        $skippedNoItem = 0;
        $linksCreated = 0;

        foreach ($legacy as $entry) {
            $meta = is_array($entry->metadata) ? $entry->metadata : [];
            $itemId = (int) ($meta['inbox_item_id'] ?? 0);
            $meetingId = (int) $entry->context_id;

            if ($itemId <= 0) {
                $skippedNoItem++;
                continue;
            }

            if (isset($alreadyMigrated[$itemId])) {
                $skippedExisting++;
                // Links trotzdem sicherstellen (Buchung war da, Link evtl. nicht).
                $linksCreated += $this->ensureLinks($itemId, $entitiesByMeeting[$meetingId] ?? [], $dryRun);
                continue;
            }

            if (! $dryRun) {
                OrganizationTimeEntry::create([
                    'team_id' => $entry->team_id,
                    'user_id' => $entry->user_id,
                    'context_type' => self::INBOX_CONTEXT,
                    'context_id' => $itemId,
                    'work_date' => $entry->work_date,
                    'minutes' => $entry->minutes,
                    'is_billed' => (bool) $entry->is_billed,
                    'note' => $entry->note,
                    'metadata' => [
                        'source' => 'meeting_inbox',
                        'inbox_item_id' => $itemId,
                        'external_event_id' => $meta['external_event_id'] ?? null,
                        'migrated_from_meeting_id' => $meetingId,
                    ],
                ]);
            }
            $migrated++;

            $linksCreated += $this->ensureLinks($itemId, $entitiesByMeeting[$meetingId] ?? [], $dryRun);
        }

        $pruned = 0;
        $prunedLinks = 0;
        if ($prune && ! $dryRun) {
            $pruned = OrganizationTimeEntry::query()
                ->where('context_type', self::MEETING_CONTEXT)
                ->where('metadata->source', 'meeting_inbox')
                ->delete();

            // Alte meeting-Knoten-Links entfernen — der Termin hängt jetzt als
            // inbox_item am Knoten (sonst doppelte Anzeige am Knoten).
            foreach ($meetingLinks as $link) {
                $entityId = (int) ($link->entity_id ?? 0);
                $meetingId = (int) $link->linkable_id;
                if ($entityId > 0 && $meetingId > 0) {
                    if (EntityDimensionBridge::deleteLink($entityId, 'meeting', $meetingId)) {
                        $prunedLinks++;
                    }
                }
            }
        } elseif ($prune && $dryRun) {
            $pruned = $legacy->count();
            $prunedLinks = $meetingLinks->count();
        }

        $this->info(sprintf(
            '%sMigration: %d Buchungen alt, %d neu auf Item, %d bereits migriert, %d ohne Item-ID, %d Knoten-Links gesetzt.',
            $dryRun ? '[dry-run] ' : '',
            $legacy->count(),
            $migrated,
            $skippedExisting,
            $skippedNoItem,
            $linksCreated,
        ));

        if ($prune) {
            $this->info(sprintf(
                '%sMeeting-Buchungen %s: %d; meeting-Knoten-Links %s: %d.',
                $dryRun ? '[dry-run] ' : '',
                $dryRun ? 'würden soft-gelöscht' : 'soft-gelöscht',
                $pruned,
                $dryRun ? 'würden entfernt' : 'entfernt',
                $prunedLinks,
            ));
        } else {
            $this->line('Alt-Buchungen bleiben stehen (meeting-Cascade ist aus → kein Doppelzählen). --prune zum Aufräumen.');
        }

        return self::SUCCESS;
    }

    /**
     * Stellt sicher, dass das Inbox-Item an denselben Knoten hängt wie das Meeting.
     *
     * @param  array<int, true>  $entityIds  [entityId => true]
     * @return int Anzahl (im Dry-Run: würde setzen).
     */
    protected function ensureLinks(int $itemId, array $entityIds, bool $dryRun): int
    {
        if (empty($entityIds)) {
            return 0;
        }

        $count = 0;
        foreach (array_keys($entityIds) as $entityId) {
            if (! $dryRun) {
                EntityDimensionBridge::createLink((int) $entityId, 'inbox_item', $itemId);
            }
            $count++;
        }

        return $count;
    }
}
