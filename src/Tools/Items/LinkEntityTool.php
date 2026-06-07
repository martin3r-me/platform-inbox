<?php

namespace Platform\Inbox\Tools\Items;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Services\InboxEntityLinkService;
use Platform\Inbox\Services\InboxRuleEngine;

/**
 * Link an inbox item to an organization entity. When also_create_rule=true
 * and the item has an identified sender, an InboxLinkRule is created so
 * future items from the same sender auto-link to the same entity.
 */
class LinkEntityTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.items.link_entity.POST';
    }

    public function getDescription(): string
    {
        return 'Verknüpft ein Inbox-Item mit einer Organization-Entity. '
            . 'Mit also_create_rule=true wird zusätzlich eine Auto-Link-Regel für den Absender angelegt, '
            . 'damit künftige Items dieses Senders automatisch verknüpft werden.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item_id' => ['type' => 'integer'],
                'entity_id' => ['type' => 'integer'],
                'also_create_rule' => [
                    'type' => 'boolean',
                    'description' => 'Default: false. Legt eine InboxLinkRule für den Absender an.',
                ],
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
        if ($entityId <= 0) {
            return ToolResult::error('VALIDATION_ERROR', 'entity_id ist erforderlich.');
        }

        $linked = app(InboxEntityLinkService::class)->link($item, $entityId);
        if (!$linked) {
            return ToolResult::error('EXECUTION_ERROR', 'Verknüpfung fehlgeschlagen oder Organization-Modul nicht verfügbar.');
        }

        $ruleCreated = false;
        if (($arguments['also_create_rule'] ?? false) && $item->sender_identifier) {
            try {
                app(InboxRuleEngine::class)->quickRuleFromManualLink($item, $entityId);
                $ruleCreated = true;
            } catch (\Throwable $e) {
                \Log::warning('Inbox: quick rule via MCP failed', ['error' => $e->getMessage()]);
            }
        }

        return ToolResult::success([
            'item_id' => $item->id,
            'entity_id' => $entityId,
            'linked' => true,
            'rule_created' => $ruleCreated,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'entity', 'link', 'rule'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
