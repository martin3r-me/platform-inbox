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
            ->orderByDesc('received_at')
            ->withCount('participants')
            ->limit($limit)
            ->get();

        return $items->map(function (InboxItem $item) {
            $start = $item->received_at ? Carbon::parse($item->received_at) : null; // = Termin-Start

            return [
                'id'                 => (int) $item->id,
                'subject'            => $item->subject ?: 'Meeting',
                'organizer'          => $item->sender_label ?: $item->sender_identifier,
                'when'               => $this->whenLabel($start),
                'time_short'         => $start ? $start->format('H:i') : '',
                'participants_count' => (int) ($item->participants_count ?? 0),
                'is_online'          => false,
                'is_recurring'       => $item->series_master_id !== null,
                'unread'             => $item->status?->value === InboxItemStatus::New->value,
            ];
        })->all();
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
            'is_recurring'  => $item->series_master_id !== null,
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
            ->map(fn ($e) => ['label' => $e['name'] ?? '—', 'icon' => 'heroicon-o-share'])
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
