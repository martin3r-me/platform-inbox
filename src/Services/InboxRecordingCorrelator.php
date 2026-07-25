<?php

namespace Platform\Inbox\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Enums\InboxItemRelation;
use Platform\Inbox\Models\InboxItem;

/**
 * Korreliert eingehende Aufnahme-/Transcript-Items (Kanal `recording`) mit dem
 * passenden `meeting`-Item — über gleichen `user_id` + Zeit-Overlap ihrer Fenster.
 *
 * Regel (mit dem Nutzer abgestimmt): **eindeutiger Overlap (genau EIN Meeting)
 * → automatisch** als `supplements`-Link (Aufnahme ⊃ Meeting); 0 oder >1 Treffer
 * → kein Auto-Link, dann übernimmt die Vorschlags-UI im Meeting-Pane.
 *
 * Quell-agnostisch: gilt für whisper, Plaud und jede weitere Audio-Quelle, die
 * über InboxAudioIngestionService reinkommt (dort trägt das Item received_at =
 * audio_recorded_at + audio_duration_seconds).
 */
class InboxRecordingCorrelator
{
    /** Toleranz an beiden Enden — man drückt selten pünktlich auf „Rec". */
    protected const BUFFER_MINUTES = 15;

    /** Fallback-Dauer, wenn Aufnahme oder Meeting keine Länge/Ende trägt. */
    protected const DEFAULT_MINUTES = 60;

    public function __construct(protected InboxItemLinkService $links) {}

    /**
     * Auto-Link bei genau einem überlappenden Meeting. Gibt das verlinkte
     * Meeting-Item zurück, sonst null (0 oder >1 → Vorschlags-UI).
     */
    public function correlate(InboxItem $recording): ?InboxItem
    {
        if (!$this->isUnlinkedRecording($recording)) {
            return null;
        }

        $candidates = $this->candidatesFor($recording);
        if ($candidates->count() !== 1) {
            return null;
        }

        $meeting = $candidates->first();
        $this->links->supplements(
            supplementaryItemId: $recording->id,
            primaryItemId: $meeting->id,
            meta: ['matched_by' => 'time_overlap', 'auto' => true],
        );

        return $meeting;
    }

    /**
     * Alle Meeting-Items (gleicher User), deren Fenster mit der Aufnahme überlappt.
     * Dient dem Auto-Link (genau 1) UND der Vorschlags-UI (>1 / für ein Meeting).
     *
     * @return Collection<int, InboxItem>
     */
    public function candidatesFor(InboxItem $recording): Collection
    {
        if ($recording->channel?->value !== Channel::Recording->value || !$recording->received_at) {
            return collect();
        }

        $recStart = Carbon::parse($recording->received_at);
        $durationSec = (int) ($recording->audio_duration_seconds ?? 0);
        $recEnd = $recStart->copy()->addSeconds($durationSec > 0 ? $durationSec : self::DEFAULT_MINUTES * 60);
        $bufferSec = self::BUFFER_MINUTES * 60;

        $meetings = InboxItem::query()
            ->where('user_id', $recording->user_id)
            ->where('channel', Channel::Meeting->value)
            ->whereBetween('received_at', [$recStart->copy()->subDay(), $recStart->copy()->addDay()])
            ->with('source')
            ->get();

        return $meetings->filter(function (InboxItem $meeting) use ($recStart, $recEnd, $bufferSec) {
            [$mStart, $mEnd] = $this->meetingWindow($meeting);
            if (!$mStart) {
                return false;
            }

            // Intervall-Overlap [recStart,recEnd] ⋂ [mStart,mEnd] mit Puffer.
            return $recStart->getTimestamp() <= $mEnd->getTimestamp() + $bufferSec
                && $recEnd->getTimestamp() >= $mStart->getTimestamp() - $bufferSec;
        })->values();
    }

    /**
     * Für ein Meeting: noch NICHT verlinkte, zeitlich passende Aufnahmen —
     * Basis der „Gehört das hierher?"-Vorschläge im Meeting-Pane.
     *
     * @return Collection<int, InboxItem>
     */
    public function suggestionsForMeeting(InboxItem $meeting): Collection
    {
        [$mStart, $mEnd] = $this->meetingWindow($meeting);
        if (!$mStart) {
            return collect();
        }
        $bufferSec = self::BUFFER_MINUTES * 60;

        $recordings = InboxItem::query()
            ->where('user_id', $meeting->user_id)
            ->where('channel', Channel::Recording->value)
            ->whereBetween('received_at', [$mStart->copy()->subDay(), $mEnd->copy()->addDay()])
            ->get();

        return $recordings->filter(function (InboxItem $rec) use ($mStart, $mEnd, $bufferSec) {
            if (!$rec->received_at) {
                return false;
            }
            $rStart = Carbon::parse($rec->received_at);
            $durationSec = (int) ($rec->audio_duration_seconds ?? 0);
            $rEnd = $rStart->copy()->addSeconds($durationSec > 0 ? $durationSec : self::DEFAULT_MINUTES * 60);

            $overlaps = $rStart->getTimestamp() <= $mEnd->getTimestamp() + $bufferSec
                && $rEnd->getTimestamp() >= $mStart->getTimestamp() - $bufferSec;

            return $overlaps && $this->links->outgoing($rec->id, InboxItemRelation::Supplements)->isEmpty();
        })->values();
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} [start, end] des Meeting-Fensters. */
    protected function meetingWindow(InboxItem $meeting): array
    {
        $session = $meeting->source;
        $start = ($session && $session->start_at) ? Carbon::parse($session->start_at)
            : ($meeting->received_at ? Carbon::parse($meeting->received_at) : null);
        if (!$start) {
            return [null, null];
        }
        $end = ($session && $session->end_at) ? Carbon::parse($session->end_at)
            : $start->copy()->addMinutes(self::DEFAULT_MINUTES);

        return [$start, $end];
    }

    /** Aufnahme, die noch an kein Meeting gehängt ist. */
    protected function isUnlinkedRecording(InboxItem $recording): bool
    {
        if ($recording->channel?->value !== Channel::Recording->value) {
            return false;
        }

        return $this->links->outgoing($recording->id, InboxItemRelation::Supplements)->isEmpty();
    }
}
