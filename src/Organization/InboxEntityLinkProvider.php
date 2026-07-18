<?php

namespace Platform\Inbox\Organization;

use Illuminate\Database\Eloquent\Builder;
use Platform\Inbox\Models\InboxItem;
use Platform\Organization\Contracts\EntityLinkProvider;

/**
 * Macht am Knoten verlinkte Inbox-Items (morph `inbox_item`) zu einem
 * Zeit-Kontext für den EntityTimeResolver.
 *
 * Zweck (Phase A): Meeting-Zeit, die MaterializeMeetingTimeCommand als echte
 * OrganizationTimeEntry mit context_type = InboxItem::class bucht, läuft dadurch
 * am Knoten in die Ist-Zeiten (time_total_minutes) auf.
 *
 * Bewusst minimal: liefert NUR die timeTrackableCascade. morphAliases() ist leer,
 * damit inbox_item KEIN Metrik-/Display-Provider wird (metrics/activityChildren
 * bleiben unangetastet) — es geht ausschließlich um die Zeit-Auflösung.
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
     * inbox_item → InboxItem, keine Child-Relations.
     * Eine TimeEntry mit context_type = InboxItem::class + context_id = inbox_item.id
     * wird damit der Entity zugerechnet, an der das Inbox-Item hängt.
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
