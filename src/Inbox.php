<?php

namespace Platform\Inbox;

use Platform\Inbox\Contracts\InboxDelivery;
use Platform\Inbox\Models\InboxItem;

/**
 * Bequeme statische Kurzform für den Push-Kontrakt.
 *
 * Andere Module (helpdesk, planner, …) liefern so in die Inbox:
 *
 *   if (class_exists(\Platform\Inbox\Inbox::class)) {
 *       \Platform\Inbox\Inbox::deliver([
 *           'user_id'      => $ticket->assignee_id,
 *           'team_id'      => $ticket->team_id,
 *           'subject'      => 'Ticket dir zugewiesen: ' . $ticket->title,
 *           'body'         => $ticket->description,
 *           'source'       => $ticket,
 *           'sender_label' => 'Helpdesk',
 *       ]);
 *   }
 *
 * class_exists-Guard = Soft-Coupling: läuft auch ohne installiertes Inbox-Modul.
 */
class Inbox
{
    /**
     * @param  array<string,mixed>  $payload  siehe InboxDelivery::deliver()
     */
    public static function deliver(array $payload): ?InboxItem
    {
        return app(InboxDelivery::class)->deliver($payload);
    }
}
