<?php

namespace Platform\Inbox\Contracts;

use Platform\Inbox\Models\InboxItem;

/**
 * Push-Kontrakt: andere Module liefern Items in die persönliche Inbox eines Users,
 * ohne das Inbox-Schema direkt anzufassen. Gegenstück zur Connector-Ingestion
 * (die zieht extern rein) — hier pushen interne Ereignisse rein
 * (Ticket zugewiesen, Erwähnung, Freigabe fällig, …).
 *
 * Soft-Coupling: Produzenten resolven das Interface über den Container und
 * guarden mit interface_exists()/app()->bound(), damit sie ohne Inbox lauffähig bleiben.
 *
 * @see \Platform\Inbox\Inbox für die statische Kurzform Inbox::deliver([...])
 */
interface InboxDelivery
{
    /**
     * Liefert ein Item in die Inbox des Users.
     *
     * Payload:
     *  - user_id (int, erforderlich)   Empfänger
     *  - team_id (int, erforderlich)   Team-Scope
     *  - subject (string, erforderlich)
     *  - body (string, optional)       Volltext
     *  - channel (string, optional)    Standard: 'system'
     *  - source (Model, optional)      Ursprungsobjekt → source_type/source_id;
     *                                  alternativ source_type + source_id explizit;
     *                                  fehlt beides, wird der User selbst als Quelle gesetzt
     *  - sender_label (string, optional)  Standard: 'System'
     *  - sender_kind (string, optional)   Standard: 'system'
     *  - importance (float, optional)  0..1
     *  - received_at (mixed, optional) Standard: now()
     *  - dedupe (bool, optional)       true → kein Duplikat je (user, source); Standard: false
     *
     * @param  array<string,mixed>  $payload
     * @return InboxItem|null  null, wenn Pflichtfelder fehlen
     */
    public function deliver(array $payload): ?InboxItem;
}
