<?php

namespace Platform\Inbox\Contracts;

/**
 * Persönliche Anruf-Sicht für home. Analog zu Meeting/Mail: Liste quellfrei aus
 * InboxItem-Feldern, Detail zieht die Call-Session (Richtung/Dauer/Nummer),
 * verlinkte Transkripte (whisper, via supplements) und Org-Knoten-Kontext.
 */
interface InboxCallQueryContract
{
    /**
     * Offene Anruf-Items des Nutzers (neueste zuerst).
     *
     * @return array<int, array{id:int, subject:string, sender:string, preview:?string,
     *   time:string, unread:bool}>
     */
    public function listForUser(int $userId, int $teamId, int $limit = 25): array;

    /**
     * Detail eines Anruf-Items: Richtung, Dauer, Nummer, ggf. Transcript, Kontext.
     * Null, wenn kein Anruf-Item.
     */
    public function detailForItem(int $inboxItemId): ?array;
}
