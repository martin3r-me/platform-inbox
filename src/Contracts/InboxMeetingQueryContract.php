<?php

namespace Platform\Inbox\Contracts;

/**
 * Query-Kontrakt für den Meeting-Kanal — home konsumiert das, statt InboxItem &
 * die user-connectors-Session direkt anzufassen. Gegenstück zu PersonTimeSummary
 * (organization). Alles normalisiert als Arrays, soft-coupled/defensiv.
 */
interface InboxMeetingQueryContract
{
    /**
     * Offene Meeting-Items eines Users (neueste zuerst) — für die Liste.
     *
     * @return array<int, array{id:int, subject:string, organizer:?string, when:string,
     *   time_short:string, participants_count:int, is_online:bool, is_recurring:bool, unread:bool}>
     */
    public function listForUser(int $userId, int $teamId, int $limit = 20): array;

    /**
     * Voll-Detail eines Meeting-Items (nach Auswahl) — für den Reading-Pane.
     * Keys passen zum Meeting-Partial: when/participants/agenda + join_url/recording/context.
     *
     * @return array<string,mixed>|null
     */
    public function detailForItem(int $inboxItemId): ?array;
}
