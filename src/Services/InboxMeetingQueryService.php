<?php

namespace Platform\Inbox\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Platform\Inbox\Contracts\InboxMeetingQueryContract;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;

/**
 * Meeting-Query für die persönliche Sicht (home). Bündelt InboxItem +
 * user-connectors-Session (via source-Morph) + Teilnehmer + Aufnahme (Segmente)
 * + Org-Knoten zu normalisierten Arrays. Defensiv: fehlende Teile → leer/null.
 */
class InboxMeetingQueryService implements InboxMeetingQueryContract
{
    public function listForUser(int $userId, int $teamId, int $limit = 20): array
    {
        $items = InboxItem::query()
            ->where('user_id', $userId)
            ->where('team_id', $teamId)
            ->where('channel', Channel::Meeting->value)
            ->where('status', InboxItemStatus::New->value)
            ->where(function ($q) {
                $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })
            ->orderByDesc('received_at')
            ->with(['source', 'participants'])
            ->limit($limit)
            ->get();

        return $items->map(function (InboxItem $item) {
            $s = $item->source; // UserConnectorMeetingSession (oder null)
            return [
                'id'                 => (int) $item->id,
                'subject'            => $s->subject ?? $item->subject ?? 'Meeting',
                'organizer'          => $s->organizer_name ?? $s->organizer_address ?? $item->sender_label,
                'when'               => $this->formatWhen($s),
                'time_short'         => $s && $s->start_at ? Carbon::parse($s->start_at)->format('H:i') : '',
                'participants_count' => $item->participants->count(),
                'is_online'          => (bool) ($s->is_online_meeting ?? false),
                'is_recurring'       => ($s->series_master_id ?? null) !== null,
                'unread'             => $item->status?->value === InboxItemStatus::New->value,
            ];
        })->all();
    }

    public function detailForItem(int $inboxItemId): ?array
    {
        $item = InboxItem::with(['source', 'participants'])->find($inboxItemId);

        if (!$item || $item->channel?->value !== Channel::Meeting->value) {
            return null;
        }

        $s = $item->source;

        $participants = $item->participants
            ->map(fn ($p) => $p->display_name ?: $p->identifier)
            ->filter()
            ->values()
            ->all();

        return [
            'channel'       => 'meeting',
            'channel_label' => 'Meeting',
            'subject'       => $s->subject ?? $item->subject ?? 'Meeting',
            'sender'        => $s->organizer_name ?? $s->organizer_address ?? 'Organisator',
            'time'          => $this->formatWhen($s),
            'summary'       => $s->body_preview ?? null,
            'when'          => $this->formatWhen($s),
            'participants'  => $participants,
            'agenda'        => $this->splitLines($s->body_preview ?? null),
            'join_url'      => $s->online_meeting_url ?? null,
            'is_recurring'  => ($s->series_master_id ?? null) !== null,
            'recording'     => $this->recording($item),
            'context'       => $this->entities($item),
            'related'       => null,
        ];
    }

    /** Org-Knoten des Items als Chips. */
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

    /** Verknüpfte Aufnahme + Transcript-Segmente (falls vorhanden). */
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

    protected function formatWhen($session): string
    {
        if (!$session || !$session->start_at) {
            return '—';
        }

        $start = Carbon::parse($session->start_at);
        $day = $start->isToday() ? 'Heute' : $start->locale('de')->isoFormat('dd D.M.');
        $times = $start->format('H:i');
        if ($session->end_at) {
            $times .= '–' . Carbon::parse($session->end_at)->format('H:i');
        }

        return trim("{$day} {$times}");
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
