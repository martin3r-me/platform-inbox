<?php

namespace Platform\Inbox\Flow;

use Platform\Core\Flow\Contracts\Automation;
use Platform\Core\Inbox\Events\ItemDelivered;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Services\InboxRecordingCorrelator;

/**
 * Smooth Transkript→Termin: sobald eine Aufnahme im Eingang landet, sofort zum
 * zeitlich passenden Termin korrelieren (genau-ein-Treffer → Auto-Link) — statt
 * auf den 15-Min-Cron zu warten. Nutzt die bestehende, bewährte Correlator-Logik
 * (Zeit-Überlappung ±1 Tag); ist via isUnlinkedRecording idempotent, komponiert
 * also sauber mit einem schon explizit gesetzten Link (Mac-Client-Upload).
 *
 * Agent-frei, deterministisch. Registriert von inbox ins Core\Flow-Gewebe; der
 * 15-Min-Cron bleibt als Backstop für out-of-order Ankünfte.
 */
class CorrelateRecordingAutomation implements Automation
{
    public function handles(): string
    {
        return ItemDelivered::class;
    }

    public function matches(object $event): bool
    {
        return $event instanceof ItemDelivered && $event->channel === 'recording';
    }

    public function run(object $event): void
    {
        /** @var ItemDelivered $event */
        $item = InboxItem::find($event->itemId);
        if (! $item) {
            return;
        }

        app(InboxRecordingCorrelator::class)->correlate($item);
    }
}
