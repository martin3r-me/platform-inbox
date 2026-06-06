<?php

namespace Platform\Inbox\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Inbox\Models\InboxItem;

/**
 * Thin adapter around the Organization module's EntityDimensionBridge.
 *
 * All access is guarded by class_exists / Schema::hasTable so Inbox stays
 * soft-coupled: if the Organization module isn't installed, all methods
 * simply return empty / false and the linking UI degrades silently.
 */
class InboxEntityLinkService
{
    protected const MORPH = 'inbox_item';

    public function enabled(): bool
    {
        return class_exists(\Platform\Organization\Services\EntityDimensionBridge::class)
            && class_exists(\Platform\Organization\Models\OrganizationEntity::class)
            && Schema::hasTable('organization_entities')
            && Schema::hasTable('organization_dimension_links');
    }

    /**
     * Return the entities currently linked to an inbox item.
     * @return array<int, array{id:int, name:string, type:string|null, code:string|null}>
     */
    public function linksFor(InboxItem $item): array
    {
        if (!$this->enabled()) {
            return [];
        }

        return $this->linksForMany([$item->id]);
    }

    /**
     * Batch lookup: return [item_id => array_of_entity_summaries]
     * @return array<int, array<int, array{id:int, name:string, type:string|null, code:string|null}>>
     */
    public function linksForItems(array $itemIds): array
    {
        if (!$this->enabled() || empty($itemIds)) {
            return [];
        }

        $links = \Platform\Organization\Services\EntityDimensionBridge::linksForLinkables(
            [self::MORPH],
            $itemIds,
            withEntity: false,
        );

        if ($links->isEmpty()) {
            return [];
        }

        // Resolve dimension_value_id → entity_id, then fetch entity rows.
        $dvIds = $links->pluck('dimension_value_id')->unique()->all();
        $dvToEntity = DB::table('organization_dimension_values')
            ->whereIn('id', $dvIds)
            ->get(['id', 'metadata'])
            ->mapWithKeys(function ($row) {
                $meta = is_string($row->metadata) ? json_decode($row->metadata, true) : $row->metadata;
                return [$row->id => $meta['source_entity_id'] ?? null];
            })
            ->filter()
            ->all();

        $entityIds = array_values(array_unique(array_filter($dvToEntity)));
        if (empty($entityIds)) {
            return [];
        }

        $entities = DB::table('organization_entities as e')
            ->leftJoin('organization_entity_types as t', 't.id', '=', 'e.entity_type_id')
            ->whereIn('e.id', $entityIds)
            ->whereNull('e.deleted_at')
            ->get(['e.id', 'e.name', 'e.code', 't.name as type_name'])
            ->keyBy('id');

        $byItem = [];
        foreach ($links as $link) {
            $entityId = $dvToEntity[$link->dimension_value_id] ?? null;
            if (!$entityId || !isset($entities[$entityId])) {
                continue;
            }
            $e = $entities[$entityId];
            $byItem[(int) $link->linkable_id][] = [
                'id' => (int) $e->id,
                'name' => $e->name,
                'type' => $e->type_name,
                'code' => $e->code,
            ];
        }

        return $byItem;
    }

    protected function linksForMany(array $itemIds): array
    {
        $batch = $this->linksForItems($itemIds);
        return $batch[$itemIds[0]] ?? [];
    }

    public function link(InboxItem $item, int $entityId): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $link = \Platform\Organization\Services\EntityDimensionBridge::createLink(
            entityId: $entityId,
            linkableType: self::MORPH,
            linkableId: $item->id,
        );
        return $link !== null;
    }

    public function unlink(InboxItem $item, int $entityId): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        return \Platform\Organization\Services\EntityDimensionBridge::deleteLink(
            entityId: $entityId,
            linkableType: self::MORPH,
            linkableId: $item->id,
        );
    }

    /**
     * Search entities by name/code within the user's team scope.
     * @return array<int, array{id:int, name:string, type:string|null, code:string|null}>
     */
    public function search(string $q, int $teamId, int $limit = 10): array
    {
        if (!$this->enabled() || trim($q) === '') {
            return [];
        }
        $like = '%' . $q . '%';

        return DB::table('organization_entities as e')
            ->leftJoin('organization_entity_types as t', 't.id', '=', 'e.entity_type_id')
            ->where('e.team_id', $teamId)
            ->whereNull('e.deleted_at')
            ->where('e.is_active', true)
            ->where(function ($qq) use ($like) {
                $qq->where('e.name', 'like', $like)
                    ->orWhere('e.code', 'like', $like);
            })
            ->orderBy('e.name')
            ->limit($limit)
            ->get(['e.id', 'e.name', 'e.code', 't.name as type_name'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'code' => $r->code,
                'type' => $r->type_name,
            ])
            ->all();
    }

    /**
     * Suggest a Person-Entity for the inbox item if the sender's email
     * matches a User whose Person-Entity is in the item's team scope.
     */
    public function suggestForItem(InboxItem $item): ?array
    {
        if (!$this->enabled() || $item->sender_kind !== 'email' || !$item->sender_identifier) {
            return null;
        }

        $userId = DB::table('users')
            ->where('email', $item->sender_identifier)
            ->value('id');
        if (!$userId) {
            return null;
        }

        $entity = DB::table('organization_entities as e')
            ->leftJoin('organization_entity_types as t', 't.id', '=', 'e.entity_type_id')
            ->where('e.team_id', $item->team_id)
            ->where('e.linked_user_id', $userId)
            ->whereNull('e.deleted_at')
            ->where('e.is_active', true)
            ->first(['e.id', 'e.name', 'e.code', 't.name as type_name']);

        if (!$entity) {
            return null;
        }

        return [
            'id' => (int) $entity->id,
            'name' => $entity->name,
            'type' => $entity->type_name,
            'code' => $entity->code,
            'reason' => 'Absender entspricht User-E-Mail dieser Person-Entity.',
        ];
    }
}
