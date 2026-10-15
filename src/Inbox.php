<?php

namespace Platform\Inbox;

use Platform\Core\Inbox\Inbox as CoreInbox;
use Platform\Inbox\Models\InboxItem;

/**
 * Rückwärtskompatibler SHIM (Phase 0 des Umbaus).
 *
 * Diese alte Kurzform bleibt bestehen, damit Produzenten (helpdesk, planner, …),
 * die noch \Platform\Inbox\Inbox::deliver([...]) rufen, NICHT brechen — auch nicht,
 * wenn der Triage-Kern in Phase 1 nach Core umzieht. Intern delegiert sie jetzt an
 * die neue Core-Naht \Platform\Core\Inbox\Inbox und lädt zur Wahrung der alten
 * Signatur (?InboxItem) das Model über die zurückgegebene ID nach.
 *
 * Neue Aufrufer sollen direkt \Platform\Core\Inbox\Inbox::deliver() nutzen (gibt ?int).
 */
class Inbox
{
    /**
     * @param  array<string,mixed>  $payload  siehe \Platform\Core\Inbox\Contracts\Delivery
     */
    public static function deliver(array $payload): ?InboxItem
    {
        $id = CoreInbox::deliver($payload);

        return $id !== null ? InboxItem::find($id) : null;
    }
}
