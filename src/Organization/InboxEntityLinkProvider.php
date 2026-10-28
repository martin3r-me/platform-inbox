<?php

namespace Platform\Inbox\Organization;

use Illuminate\Database\Eloquent\Builder;
use Platform\Core\Inbox\Models\InboxItem;
use Platform\Organization\Contracts\EntityLinkProvider;

/**
 * Inbox-Item als linkbares Objekt + Zeit-Träger am Org-Baum (morph `inbox_item`).
 *
 * Historie:
 *   - Phase A: Inbox-Item war Zeit-Kontext (context_type = InboxItem).
 *   - Phase C: Zeit zog aufs MEETING (context_type = Meeting); Cascade hier leer,
 *     um Doppelzählung über zwei Links (inbox_item UND meeting) zu vermeiden.
 *   - Phase 2 der Meetings-Auflösung (jetzt): das meetings-Modul fällt weg,
 *     „Termin = Inbox-Item". Die Ist-Zeit kehrt auf das Inbox-Item zurück
 *     (context_type = InboxItem). Weil der konkurrierende `meeting`-Morph mit dem
 *     Modul verschwindet, gibt es KEIN Doppelzählen mehr.
 *
 * MaterializeMeetingTimeCommand bucht je Teilnehmer-Item eine OrganizationTimeEntry
 * (context_type = InboxItem, context_id = item_id); der EntityTimeResolver rollt sie
 * über diesen Cascade auf den Knoten. Serie/Teilnehmer: der Knoten-Link propagiert
 * über die Unit (gleiche ical_uid) auf alle Vorkommen + Beteiligten — je Person ein
 * eigenes Item mit eigener Buchung, Summe am gemeinsamen Knoten.
 */
class InboxEntityLinkProvider implements EntityLinkProvider
{
    public function morphAliases(): array
    {
        return ['inbox_item'];
    }

    public function linkTypeConfig(): array
    {
        return [
            'inbox_item' => [
                'label' => 'Termine & Eingänge',
                'singular' => 'Eingang',
                'icon' => 'heroicon-o-inbox',
                'route' => null,
            ],
        ];
    }

    public function applyEagerLoading(Builder $query, string $morphAlias, string $fqcn): void
    {
        // no-op
    }

    public function extractMetadata(string $morphAlias, mixed $model): array
    {
        return [];
    }

    public function metadataDisplayRules(): array
    {
        return [];
    }

    /**
     * Inbox-Item trägt die Ist-Zeit direkt (keine Child-Relations): die Buchung
     * hängt am Item selbst (context_type = InboxItem, context_id = item_id).
     */
    public function timeTrackableCascades(): array
    {
        return [
            'inbox_item' => [InboxItem::class, []],
        ];
    }

    public function metrics(string $morphAlias, array $linksByEntity): array
    {
        return [];
    }

    public function activityChildren(string $morphAlias, array $linkableIds): array
    {
        return [];
    }
}
