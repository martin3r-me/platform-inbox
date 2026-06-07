<?php

namespace Platform\Inbox\Tools\Items;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;

/**
 * Bulk triage one or more inbox items in a single call. Actions:
 *   - done:    status=done, handled_at=now
 *   - ignored: status=ignored, handled_at=now
 *   - snooze:  status=snoozed, snoozed_until=now+hours
 *   - new:     re-open (status=new, handled_at=null, snoozed_until=null)
 *
 * Idempotent: re-triaging an already-done item is a no-op.
 */
class TriageItemsTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.items.triage.POST';
    }

    public function getDescription(): string
    {
        return 'Triagiert ein oder mehrere Inbox-Items in einem Call: '
            . 'action=done|ignored|snooze|new. snooze braucht zusätzlich hours (default 4). '
            . 'Idempotent.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ids' => [
                    'type' => 'array',
                    'description' => 'Eine oder mehrere Inbox-Item-IDs.',
                    'items' => ['type' => 'integer'],
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => ['done', 'ignored', 'snooze', 'new'],
                ],
                'hours' => [
                    'type' => 'integer',
                    'description' => 'Snooze-Dauer in Stunden (nur für action=snooze). Default: 4.',
                    'minimum' => 1,
                    'maximum' => 720,
                ],
            ],
            'required' => ['ids', 'action'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $ids = array_filter(array_map('intval', (array) ($arguments['ids'] ?? [])));
        $action = (string) ($arguments['action'] ?? '');
        $hours = max(1, (int) ($arguments['hours'] ?? 4));

        if (empty($ids) || $action === '') {
            return ToolResult::error('VALIDATION_ERROR', 'ids und action sind erforderlich.');
        }

        $payload = match ($action) {
            'done' => ['status' => InboxItemStatus::Done->value, 'handled_at' => now(), 'snoozed_until' => null],
            'ignored' => ['status' => InboxItemStatus::Ignored->value, 'handled_at' => now(), 'snoozed_until' => null],
            'snooze' => ['status' => InboxItemStatus::Snoozed->value, 'snoozed_until' => now()->addHours($hours)],
            'new' => ['status' => InboxItemStatus::New->value, 'handled_at' => null, 'snoozed_until' => null],
            default => null,
        };
        if ($payload === null) {
            return ToolResult::error('VALIDATION_ERROR', "Unknown action: $action");
        }

        $updated = InboxItem::query()
            ->where('user_id', $context->user->id)
            ->whereIn('id', $ids)
            ->update($payload);

        return ToolResult::success([
            'action' => $action,
            'updated_count' => $updated,
            'requested_ids' => $ids,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'items', 'triage', 'bulk'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
