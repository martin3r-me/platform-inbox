<?php

namespace Platform\Inbox\Organization;

use Illuminate\Database\Eloquent\Builder;
use Platform\Organization\Contracts\EntityLinkProvider;

/**
 * Historisch (Phase A): machte am Knoten verlinkte Inbox-Items (morph `inbox_item`)
 * zum Zeit-Kontext, damit Meeting-Zeit mit context_type = InboxItem::class am Knoten
 * aufläuft.
 *
 * Ab Phase C bucht MaterializeMeetingTimeCommand die Ist-Zeit direkt auf das
 * MEETING (context_type = Meeting::class), nicht mehr aufs Inbox-Item — das Meeting
 * ist der fachliche Kontext (Titel, Agenda) statt eines Infrastruktur-Objekts.
 * timeTrackableCascades() ist daher jetzt LEER, sonst würde derselbe Termin über
 * zwei Knoten-Links (inbox_item UND meeting) doppelt gezählt.
 */
class InboxEntityLinkProvider implements EntityLinkProvider
{
    public function morphAliases(): array
    {
        return [];
    }

    public function linkTypeConfig(): array
    {
        return [];
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
     * Leer: Die Meeting-Ist-Zeit hängt ab Phase C am Meeting (morph `meeting`),
     * nicht mehr am Inbox-Item — siehe MeetingsEntityLinkProvider.
     */
    public function timeTrackableCascades(): array
    {
        return [];
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
