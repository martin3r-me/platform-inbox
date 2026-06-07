<?php

namespace Platform\Inbox\Tools\Items;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Models\InboxAutoLinkEvent;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Services\InboxEntityLinkService;

class UnlinkEntityTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.items.unlink_entity.POST';
    }

    public function getDescription(): string
    {
        return 'Löst die Verknüpfung zwischen Inbox-Item und Organization-Entity. '
            . 'Etwaige Auto-Link-Events für dasselbe Paar werden ebenfalls gelöscht.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item_id' => ['type' => 'integer'],
                'entity_id' => ['type' => 'integer'],
            ],
            'required' => ['item_id', 'entity_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $item = InboxItem::query()
            ->where('id', (int) ($arguments['item_id'] ?? 0))
            ->where('user_id', $context->user->id)
            ->first();
        if (!$item) {
            return ToolResult::error('NOT_FOUND', 'Item nicht gefunden oder kein Zugriff.');
        }

        $entityId = (int) ($arguments['entity_id'] ?? 0);
        $unlinked = app(InboxEntityLinkService::class)->unlink($item, $entityId);
        if ($unlinked) {
            InboxAutoLinkEvent::where('inbox_item_id', $item->id)
                ->where('entity_id', $entityId)
                ->delete();
        }

        return ToolResult::success([
            'item_id' => $item->id,
            'entity_id' => $entityId,
            'unlinked' => $unlinked,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'entity', 'unlink'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
