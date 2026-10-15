<?php

namespace Platform\Inbox\Support;

use Platform\Core\Inbox\Contracts\Delivery as CoreDelivery;
use Platform\Inbox\Contracts\InboxDelivery;

/**
 * Übergangs-Bridge (Phase 0): verbindet die neue Core-Naht
 * \Platform\Core\Inbox\Contracts\Delivery mit der bestehenden inbox-internen
 * InboxDelivery-Implementierung und mappt deren Rückgabe (?InboxItem) auf die
 * neutrale Core-Signatur (?int).
 *
 * Solange der Triage-Kern noch im inbox-Modul lebt, bindet InboxServiceProvider
 * den Core-Port auf diese Bridge. Zieht der Kern nach Core um (Phase 1), bindet
 * Core den Port direkt auf den dann dort liegenden Service — diese Bridge und
 * das alte InboxDelivery-Interface fallen weg.
 */
class CoreDeliveryBridge implements CoreDelivery
{
    public function __construct(
        protected InboxDelivery $inner,
    ) {}

    public function deliver(array $payload): ?int
    {
        return $this->inner->deliver($payload)?->id;
    }
}
