<?php

namespace Platform\Inbox\Contracts;

/**
 * Persönliche Mail-Sicht für home. Analog zu InboxMeetingQueryContract:
 * die Liste kommt quellfrei aus InboxItem-Feldern, das Detail zieht den
 * vollen Thread (alle Mails derselben conversation_id) + Org-Knoten-Kontext.
 */
interface InboxMailQueryContract
{
    /**
     * Offene Mail-Items des Nutzers (neueste zuerst).
     *
     * @return array<int, array{id:int, subject:string, sender:string, preview:?string,
     *   time:string, unread:bool, has_attachments:bool}>
     */
    public function listForUser(int $userId, int $teamId, int $limit = 25): array;

    /**
     * Detail eines Mail-Items: Betreff, Absender, voller Thread (Verlauf) und
     * Org-Knoten-Kontext. Null, wenn kein Mail-Item.
     */
    public function detailForItem(int $inboxItemId): ?array;
}
