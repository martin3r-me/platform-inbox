<?php

namespace Platform\Inbox\Services;

use Platform\Core\Inbox\Contracts\ItemHandoff;
use Platform\Inbox\Models\InboxItem;

/**
 * Core-ItemHandoff-Naht, implementiert über den bestehenden InboxHandoffService.
 * Löst das Item per ID auf und delegiert an die erprobte Planner-/Helpdesk-Übergabe
 * (idempotent). So übergibt home ohne Inbox-Model und ohne Ziel-Modul-Wissen.
 */
class ItemHandoffService implements ItemHandoff
{
    public function available(string $target): bool
    {
        $svc = app(InboxHandoffService::class);

        return match ($target) {
            'task'   => $svc->plannerAvailable(),
            'ticket' => $svc->helpdeskAvailable(),
            default  => false,
        };
    }

    public function handoff(int $itemId, string $target): bool
    {
        $item = InboxItem::find($itemId);
        if (! $item) {
            return false;
        }

        $svc = app(InboxHandoffService::class);
        $userId = (int) $item->user_id;

        $result = match ($target) {
            'task'   => $svc->itemToPlannerTask($item, $userId),
            'ticket' => $svc->itemToHelpdeskTicket($item, $userId),
            default  => null,
        };

        return $result !== null;
    }
}
