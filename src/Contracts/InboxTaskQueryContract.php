<?php

namespace Platform\Inbox\Contracts;

/**
 * Persönliche Aufgaben-Sicht für home. Aufgaben-Items entstehen NICHT über einen
 * Connector, sondern per Producer-Push (`Inbox::deliver()`) aus dem planner, wenn
 * eine Aufgabe AN den Nutzer zugewiesen wird. Liste quellfrei; Detail liefert
 * Titel, Zuweiser, Beschreibung, Task-Referenz (Deep-Link) + Org-Knoten-Kontext.
 */
interface InboxTaskQueryContract
{
    /**
     * @return array<int, array{id:int, subject:string, sender:string, preview:?string,
     *   time:string, unread:bool}>
     */
    public function listForUser(int $userId, int $teamId, int $limit = 25): array;

    public function detailForItem(int $inboxItemId): ?array;
}
