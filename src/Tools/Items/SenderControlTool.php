<?php

namespace Platform\Inbox\Tools\Items;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Enums\SubscriptionStatus;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxSenderSubscription;

/**
 * Sender-scoped controls — operate on the (user, sender_kind, sender_identifier)
 * subscription instead of the individual item. Affects every future item from
 * the same sender:
 *   - mute:        future items land as 'ignored' (still in DB)
 *   - unsubscribe: ingest skips them entirely
 *   - subscribe:   reset to default (visible in inbox)
 *   - vip:         flag as important (visual highlight + filter)
 *   - unvip:       clear VIP flag
 */
class SenderControlTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.items.sender.POST';
    }

    public function getDescription(): string
    {
        return 'Sender-Operationen anhand eines Inbox-Items: mute (künftige Items → ignored), '
            . 'unsubscribe (Ingest überspringt sie ganz), subscribe (Reset), vip / unvip. '
            . 'Wirkt für alle künftigen Items des gleichen Absenders.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'item_id' => ['type' => 'integer', 'description' => 'Inbox-Item-ID — Sender wird daraus abgeleitet.'],
                'op' => ['type' => 'string', 'enum' => ['mute', 'unsubscribe', 'subscribe', 'vip', 'unvip']],
            ],
            'required' => ['item_id', 'op'],
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
        if (!$item->sender_kind || !$item->sender_identifier) {
            return ToolResult::error('VALIDATION_ERROR', 'Item hat keinen identifizierten Absender.');
        }

        $op = (string) $arguments['op'];

        $sub = InboxSenderSubscription::firstOrCreate(
            [
                'user_id' => $context->user->id,
                'sender_kind' => $item->sender_kind,
                'sender_identifier' => $item->sender_identifier,
            ],
            [
                'team_id' => $item->team_id,
                'status' => SubscriptionStatus::Subscribed->value,
                'is_vip' => false,
                'label' => $item->sender_label,
                'last_seen_at' => $item->received_at,
            ],
        );

        switch ($op) {
            case 'mute':
                $sub->update(['status' => SubscriptionStatus::Muted->value]);
                break;
            case 'unsubscribe':
                $sub->update(['status' => SubscriptionStatus::Unsubscribed->value]);
                // Also drop the current item from the open list — otherwise the
                // user "unsubscribes" but still has to manually ignore the row.
                InboxItem::where('id', $item->id)
                    ->where('user_id', $context->user->id)
                    ->update(['status' => InboxItemStatus::Ignored->value, 'handled_at' => now()]);
                break;
            case 'subscribe':
                $sub->update(['status' => SubscriptionStatus::Subscribed->value]);
                break;
            case 'vip':
                $sub->update(['is_vip' => true]);
                break;
            case 'unvip':
                $sub->update(['is_vip' => false]);
                break;
            default:
                return ToolResult::error('VALIDATION_ERROR', "Unknown op: $op");
        }

        return ToolResult::success([
            'op' => $op,
            'sender_kind' => $item->sender_kind,
            'sender_identifier' => $item->sender_identifier,
            'subscription' => [
                'status' => $sub->status?->value,
                'is_vip' => (bool) $sub->is_vip,
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'sender', 'subscription', 'mute', 'unsubscribe', 'vip'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
