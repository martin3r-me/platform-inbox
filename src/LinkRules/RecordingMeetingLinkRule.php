<?php

namespace Platform\Inbox\LinkRules;

use Platform\Core\Inbox\Contracts\LinkRule;
use Platform\Inbox\Models\InboxItem;
use Platform\Core\Inbox\Services\InboxRecordingCorrelator;

/**
 * LinkRule: Aufnahme ↔ Meeting per Zeit-Overlap. Delegiert an den Correlator
 * (eindeutiger Treffer → Auto-Link; 0/>1 → Vorschlags-UI). Idempotent und
 * selbst-filternd — für Nicht-Aufnahmen und bereits verknüpfte Items ein No-op.
 */
class RecordingMeetingLinkRule implements LinkRule
{
    public function apply(int $itemId): void
    {
        $item = InboxItem::find($itemId);
        if (! $item) {
            return;
        }

        app(InboxRecordingCorrelator::class)->correlate($item);
    }
}
