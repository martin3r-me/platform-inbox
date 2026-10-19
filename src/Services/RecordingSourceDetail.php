<?php

namespace Platform\Inbox\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Inbox\Contracts\SourceDetail;
use Platform\Inbox\Enums\InboxItemRelation;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemEnrichment;
use Platform\Inbox\Services\InboxItemLinkService;
use Platform\Inbox\Services\InboxRecordingCorrelator;

/**
 * Live-Detail für den Aufnahme-Kanal: Transkript-Segmente + Dauer + KI-Anreicherung
 * (Zusammenfassung, Action-Items) + der zugeordnete Termin für den Reading-Pane.
 * Quellen-agnostisch — die Daten liegen beim Ingest schon auf dem Inbox-Item
 * (inbox_item_segments + audio_duration_seconds, Enrichment in inbox_item_enrichments,
 * Termin-Zuordnung als supplements-Link), egal ob die Aufnahme von Plaud, Roh-Audio
 * oder einem künftigen Anbieter via whisper kam.
 *
 * Registriert in der Core-SourceDetailRegistry; Core\StreamQuery::detailForItem
 * merged das für channel='recording' dazu.
 */
class RecordingSourceDetail implements SourceDetail
{
    public function channel(): string
    {
        return 'recording';
    }

    public function detailForItem(int $inboxItemId): ?array
    {
        if (! Schema::hasTable('inbox_items') || ! Schema::hasTable('inbox_item_segments')) {
            return null;
        }

        $item = DB::table('inbox_items')->where('id', $inboxItemId)->first();
        if (! $item || ($item->channel ?? null) !== 'recording') {
            return null;
        }

        // Speaker-Label → Anzeigename (aus Participants), sonst das Label selbst.
        $names = [];
        if (Schema::hasTable('inbox_item_participants')) {
            foreach (
                DB::table('inbox_item_participants')
                    ->where('inbox_item_id', $inboxItemId)
                    ->where('role', 'speaker')
                    ->get() as $p
            ) {
                if (! empty($p->identifier)) {
                    $names[$p->identifier] = $p->display_name ?: $p->identifier;
                }
            }
        }

        $segments = DB::table('inbox_item_segments')
            ->where('inbox_item_id', $inboxItemId)
            ->orderBy('start_seconds')
            ->get()
            ->map(fn ($s) => [
                $this->fmt((int) ($s->start_seconds ?? 0)),
                $names[$s->speaker_label] ?? ($s->speaker_label ?: 'Sprecher'),
                (string) ($s->text ?? ''),
            ])
            ->all();

        return array_merge([
            'channel'       => 'recording',
            'channel_label' => 'Aufnahme',
            'duration'      => $this->fmt((int) ($item->audio_duration_seconds ?? 0)),
            'segments'      => $segments,
        ], $this->enrichment($inboxItemId), $this->meetingSection($inboxItemId));
    }

    /**
     * KI-Anreicherung des Items (Template „meeting-transcript"): Zusammenfassung,
     * TL;DR, Agenda, Entscheidungen, Action-Items. Solange der Job läuft, kommt
     * `enrichment_status` = running/pending zurück → UI zeigt „wird erstellt".
     *
     * @return array<string,mixed>
     */
    protected function enrichment(int $inboxItemId): array
    {
        if (! Schema::hasTable('inbox_item_enrichments')) {
            return ['enrichment_status' => 'none'];
        }

        $done = InboxItemEnrichment::query()
            ->where('inbox_item_id', $inboxItemId)
            ->where('status', InboxItemEnrichment::STATUS_DONE)
            ->orderByDesc('is_primary')
            ->orderByDesc('run_at')
            ->first();

        if (! $done) {
            $pending = InboxItemEnrichment::query()
                ->where('inbox_item_id', $inboxItemId)
                ->whereIn('status', [InboxItemEnrichment::STATUS_PENDING, InboxItemEnrichment::STATUS_RUNNING])
                ->exists();

            return ['enrichment_status' => $pending ? 'running' : 'none'];
        }

        $out = is_array($done->output) ? $done->output : [];

        return [
            'enrichment_status' => 'done',
            'headline'      => $out['headline'] ?? null,
            'tldr'          => $out['tldr'] ?? null,
            'summary'       => $out['summary'] ?? null,
            'agenda'        => array_values((array) ($out['agenda'] ?? [])),
            'decisions'     => array_values((array) ($out['decisions'] ?? [])),
            // Action-Items als Objekte {text, suggested_owner, due_hint}.
            'action_items'  => array_values((array) ($out['action_items'] ?? [])),
        ];
    }

    /**
     * Termin-Zuordnung: ist die Aufnahme verknüpft (supplements-Link), liefert
     * sie `linked_meeting`; sonst `meeting_suggestions` (zeitlich passende Termine
     * aus dem Correlator) für die „Gehört das hierher?"-UI im Pane.
     *
     * @return array<string,mixed>
     */
    protected function meetingSection(int $inboxItemId): array
    {
        if (! Schema::hasTable('inbox_item_links')) {
            return [];
        }

        try {
            $link = app(InboxItemLinkService::class)
                ->outgoing($inboxItemId, InboxItemRelation::Supplements)
                ->first();

            if ($link) {
                $meeting = DB::table('inbox_items')->where('id', $link->to_inbox_item_id)->first();
                if ($meeting) {
                    return ['linked_meeting' => [
                        'inbox_id' => (int) $meeting->id,
                        'subject'  => $meeting->subject ?? 'Termin',
                        'time'     => $this->when($meeting->received_at ?? null),
                        'auto'     => (bool) (is_array($link->meta) ? ($link->meta['auto'] ?? false) : false),
                    ]];
                }
            }

            // Nicht verknüpft → Vorschläge (zeitlich passende Meetings/Calls).
            $recording = InboxItem::find($inboxItemId);
            if (! $recording) {
                return [];
            }
            $suggestions = app(InboxRecordingCorrelator::class)
                ->candidatesFor($recording)
                ->map(fn ($m) => [
                    'inbox_id' => (int) $m->id,
                    'subject'  => $m->subject ?? 'Termin',
                    'time'     => $this->when($m->received_at ?? null),
                ])
                ->values()
                ->all();

            return ['meeting_suggestions' => $suggestions];
        } catch (\Throwable) {
            return [];
        }
    }

    protected function when(mixed $v): string
    {
        if (! $v) {
            return '';
        }
        try {
            return Carbon::parse($v)->locale('de')->isoFormat('dd D.M. HH:mm');
        } catch (\Throwable) {
            return '';
        }
    }

    protected function fmt(int $s): string
    {
        if ($s <= 0) {
            return '0:00';
        }
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
    }
}
