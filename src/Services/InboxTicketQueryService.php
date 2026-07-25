<?php

namespace Platform\Inbox\Services;

use Platform\Inbox\Contracts\InboxTicketQueryContract;
use Platform\Inbox\Enums\Channel;

/**
 * Ticket-Sicht: Ticket-Items kommen per deliver() aus dem helpdesk (Zuweisung an
 * den Nutzer). Alles Gemeinsame steckt in AbstractInboxAssignedQueryService.
 */
class InboxTicketQueryService extends AbstractInboxAssignedQueryService implements InboxTicketQueryContract
{
    protected function channel(): Channel
    {
        return Channel::Ticket;
    }

    protected function label(): string
    {
        return 'Ticket';
    }

    protected function sourceMorph(): string
    {
        return 'helpdesk_ticket';
    }

    protected function refKey(): string
    {
        return 'ticket_id';
    }
}
