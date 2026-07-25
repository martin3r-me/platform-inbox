<?php

namespace Platform\Inbox\Contracts;

/**
 * Persönliche Ticket-Sicht für home. Ticket-Items entstehen per Producer-Push
 * (`Inbox::deliver()`) aus dem helpdesk, wenn ein Ticket AN den Nutzer zugewiesen
 * wird. Liste quellfrei; Detail: Titel, Zuweiser, Beschreibung, Ticket-Referenz
 * (Deep-Link) + Org-Knoten-Kontext.
 */
interface InboxTicketQueryContract
{
    /**
     * @return array<int, array{id:int, subject:string, sender:string, preview:?string,
     *   time:string, unread:bool}>
     */
    public function listForUser(int $userId, int $teamId, int $limit = 25): array;

    public function detailForItem(int $inboxItemId): ?array;
}
