<?php

namespace Platform\Inbox\Services;

use Illuminate\Support\Carbon;
use Platform\Inbox\Contracts\InboxMeetingQueryContract;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;

/**
 * Meeting-Query für die persönliche Sicht (home). Die LISTE kommt komplett aus
 * InboxItem-Feldern (kein source-Morph nötig — robust). Erst das DETAIL zieht die
 * user-connectors-Session + Teilnehmer + Aufnahme + Org-Knoten, defensiv/soft-coupled.
 *
 * Inbox ist user-scoped (wie das kanonische ListItemsTool) — KEIN team_id-Filter.
 */
class InboxMeetingQueryService implements InboxMeetingQueryContract
{
    public function listForUser(int $userId, int $teamId, int $limit = 20): array
    {
        $items = InboxItem::query()
            ->where('user_id', $userId)
            ->where('channel', Channel::Meeting->value)
            ->where('status', InboxItemStatus::New->value)
            ->where(function ($q) {
                $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })
            ->orderBy('received_at')  // aufsteigend: für Serien-Repräsentanten-Wahl
            ->withCount('participants')
            ->get();

        $today = Carbon::today();

        // Nach realer Termin-Identität gruppieren: alle Vorkommen desselben Termins
        // (iCalUId, Fallback series_master_id) → EINE Zeile. iCalUId ist über
        // Vorkommen UND Postfächer stabil; series_master_id nur pro Postfach.
        $groups = [];
        foreach ($items as $item) {
            $identity = $item->ical_uid ?: $item->series_master_id;
            $key = $identity ? 'id:' . $identity : 'single:' . $item->id;
            $groups[$key][] = $item;
        }

        $rows = [];
        foreach ($groups as $occurrences) {
            // Repräsentant: nächstes ANSTEHENDES Vorkommen, sonst jüngstes vergangenes.
            $rep = null;
            foreach ($occurrences as $o) {
                $s = $o->received_at ? Carbon::parse($o->received_at) : null;
                if ($s && $s->gte($today)) {
                    $rep = $o;
                    break;
                }
            }
            if (!$rep) {
                $rep = end($occurrences); // asc sortiert → letztes = jüngstes vergangenes
            }

            $start = $rep->received_at ? Carbon::parse($rep->received_at) : null;
            $upcoming = $start && $start->gte($today);

            $rows[] = [
                'id'                 => (int) $rep->id,
                'subject'            => $rep->subject ?: 'Meeting',
                'organizer'          => $rep->sender_label ?: $rep->sender_identifier,
                'when'               => $this->whenLabel($start),
                'time_short'         => $start ? $start->format('H:i') : '',
                'participants_count' => (int) ($rep->participants_count ?? 0),
                'is_series'          => $rep->series_master_id !== null || count($occurrences) > 1,
                'series_count'       => count($occurrences),
                'section'            => $upcoming ? 'upcoming' : 'past',
                'unread'             => $rep->status?->value === InboxItemStatus::New->value,
                '_ts'                => $start ? $start->getTimestamp() : 0,
            ];
        }

        // Anstehend (aufsteigend, nächster zuerst) VOR Vergangen (absteigend, jüngster zuerst).
        usort($rows, function ($a, $b) {
            if ($a['section'] !== $b['section']) {
                return $a['section'] === 'upcoming' ? -1 : 1;
            }
            return $a['section'] === 'upcoming'
                ? $a['_ts'] <=> $b['_ts']
                : $b['_ts'] <=> $a['_ts'];
        });

        $rows = array_slice($rows, 0, max($limit, 50));
        foreach ($rows as &$r) {
            unset($r['_ts']);
        }

        return $rows;
    }

    public function detailForItem(int $inboxItemId): ?array
    {
        $item = InboxItem::with(['participants'])->find($inboxItemId);

        if (!$item || $item->channel?->value !== Channel::Meeting->value) {
            return null;
        }

        // Session defensiv laden (morphTo kann fehlschlagen, wenn Quelle weg ist).
        $s = null;
        try {
            $s = $item->source;
        } catch (\Throwable $e) {
            $s = null;
        }

        $start = $s && $s->start_at ? Carbon::parse($s->start_at)
            : ($item->received_at ? Carbon::parse($item->received_at) : null);
        $end = $s && $s->end_at ? Carbon::parse($s->end_at) : null;

        $participants = $item->participants
            ->map(fn ($p) => $p->display_name ?: $p->identifier)
            ->filter()
            ->values()
            ->all();

        // Wie viele offene Vorkommen desselben Termins (iCalUId, Fallback series_master_id)?
        $identity = $item->ical_uid ?: $item->series_master_id;
        $seriesCount = $identity
            ? InboxItem::where('user_id', $item->user_id)
                ->where($item->ical_uid ? 'ical_uid' : 'series_master_id', $identity)
                ->where('status', InboxItemStatus::New->value)
                ->count()
            : 1;

        return [
            'channel'       => 'meeting',
            'channel_label' => 'Meeting',
            'subject'       => ($s->subject ?? null) ?: ($item->subject ?: 'Meeting'),
            'sender'        => ($s->organizer_name ?? null) ?: ($s->organizer_address ?? $item->sender_label ?? 'Organisator'),
            'time'          => $this->whenRange($start, $end),
            'summary'       => ($s->body_preview ?? null) ?: null,
            'when'          => $this->whenRange($start, $end),
            'participants'  => $participants,
            'agenda'        => $this->splitLines(($s->body_preview ?? null) ?: ($item->body ?? $item->preview ?? null)),
            'join_url'      => $s->online_meeting_url ?? null,
            'is_series'     => $item->series_master_id !== null || $seriesCount > 1,
            'series_count'  => $seriesCount,
            'meeting_id'    => $item->meeting_id,   // gesetzt = zu echtem Meeting promotet (Inbox-Feld)
            'recording'     => $this->recording($item),
            'context'       => $this->entities($item),
            'related'       => null,
        ];
    }

    protected function entities(InboxItem $item): array
    {
        try {
            $links = app(InboxEntityLinkService::class)->linksFor($item);
        } catch (\Throwable $e) {
            return [];
        }

        return collect($links)
            ->map(fn ($e) => [
                'id'    => $e['id'] ?? null,
                'label' => $e['name'] ?? '—',
                'path'  => $e['path'] ?? null,
                'icon'  => 'heroicon-o-share',
            ])
            ->all();
    }

    protected function recording(InboxItem $item): ?array
    {
        try {
            $links = app(InboxItemLinkService::class)->supplementaryFor($item->id);
            $rec = $links->first()?->fromItem;
            if (!$rec) {
                return null;
            }

            $segments = $rec->segments()
                ->orderBy('start_seconds')
                ->limit(30)
                ->get()
                ->map(fn ($seg) => [
                    $this->fmtSeconds((int) $seg->start_seconds),
                    $seg->speaker_label ?: 'Sprecher',
                    (string) $seg->text,
                ])
                ->all();

            return empty($segments) ? null : ['segments' => $segments];
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function whenLabel(?Carbon $start): string
    {
        if (!$start) {
            return '—';
        }
        $day = $start->isToday() ? 'Heute' : $start->locale('de')->isoFormat('dd D.M.');
        return trim("{$day} " . $start->format('H:i'));
    }

    protected function whenRange(?Carbon $start, ?Carbon $end): string
    {
        if (!$start) {
            return '—';
        }
        $label = $this->whenLabel($start);
        if ($end) {
            $label .= '–' . $end->format('H:i');
        }
        return $label;
    }

    /** @return array<int,string> */
    protected function splitLines(?string $text): array
    {
        if (!$text) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $text))
            ->map(fn ($l) => trim(strip_tags($l)))
            ->filter()
            ->take(8)
            ->values()
            ->all();
    }

    protected function fmtSeconds(int $s): string
    {
        return sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
    }
}
