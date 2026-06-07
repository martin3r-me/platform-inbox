<?php

namespace Platform\Inbox\Tools\Items;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemHandoff;
use Platform\Inbox\Services\InboxHandoffService;

/**
 * Item-level handoff: turn an inbox item into a Planner-Task or a
 * Helpdesk-Ticket. Idempotent — if a handoff of that kind already exists,
 * the existing one is returned (no duplicate target row).
 */
class HandoffTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.items.handoff.POST';
    }

    public function getDescription(): string
    {
        return 'Erzeugt aus einem Inbox-Item einen Planner-Task oder ein Helpdesk-Ticket. '
            . 'Idempotent — vorhandene Handoffs werden wiederverwendet.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item_id' => ['type' => 'integer'],
                'kind' => ['type' => 'string', 'enum' => ['planner_task', 'helpdesk_ticket']],
            ],
            'required' => ['item_id', 'kind'],
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

        $kind = (string) ($arguments['kind'] ?? '');
        $svc = app(InboxHandoffService::class);

        $handoff = match ($kind) {
            InboxItemHandoff::KIND_PLANNER_TASK => $svc->itemToPlannerTask($item, $context->user->id),
            InboxItemHandoff::KIND_HELPDESK_TICKET => $svc->itemToHelpdeskTicket($item, $context->user->id),
            default => null,
        };

        if ($handoff === null) {
            return ToolResult::error('EXECUTION_ERROR', 'Handoff nicht möglich — Zielmodul fehlt oder Handoff fehlgeschlagen.');
        }

        return ToolResult::success([
            'item_id' => $item->id,
            'kind' => $handoff->kind,
            'target_type' => $handoff->target_type,
            'target_id' => $handoff->target_id,
            'created_at' => $handoff->created_at?->toIso8601String(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'handoff', 'planner', 'helpdesk'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
