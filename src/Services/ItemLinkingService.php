<?php

namespace Platform\Inbox\Services;

use Platform\Core\Inbox\Contracts\ItemLinking;

/**
 * Core-ItemLinking-Naht, implementiert über den InboxRecordingCorrelator. Die
 * manuelle Aufnahme→Termin-Zuordnung (inkl. Org-Kontext-Kaskade) läuft darüber;
 * home braucht den Correlator nicht mehr direkt.
 */
class ItemLinkingService implements ItemLinking
{
    public function assign(int $itemId, int $anchorId): void
    {
        app(InboxRecordingCorrelator::class)->assignToMeeting($itemId, $anchorId);
    }

    public function unassign(int $itemId): void
    {
        app(InboxRecordingCorrelator::class)->clearAssignment($itemId);
    }
}
