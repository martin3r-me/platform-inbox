<?php

namespace Platform\Inbox\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Inbox\Contracts\InboxCallQueryContract;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;

/**
 * Anruf-Query für die persönliche Sicht (home). Liste quellfrei aus InboxItem-
 * Feldern; Detail zieht die Call-Session (Richtung/Dauer/Nummer), verlinkte
 * Transkripte (whisper via supplements) und Org-Knoten-Kontext — loose über DB::table.
 */
class InboxCallQueryService implements InboxCallQueryContract
{
    public function listForUser(int $userId, int $teamId, int $limit = 25): array
    {
        $items = InboxItem::query()
            ->where('user_id', $userId)
            ->where('channel', Channel::Call->value)
            ->where('status', InboxItemStatus::New->value)
            ->where(function ($q) {
                $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })
            ->orderByDesc('received_at')
            ->limit($limit)
            ->get();

        return $items->map(fn (InboxItem $item) => [
            'id'      => (int) $item->id,
            'subject' => $item->subject ?: 'Anruf',
            'sender'  => $item->sender_label ?: ($item->sender_identifier ?: 'Unbekannt'),
            'preview' => $item->preview,
            'time'    => $this->timeLabel($item->received_at ? Carbon::parse($item->received_at) : null),
            'unread'  => $item->status?->value === InboxItemStatus::New->value,
        ])->all();
    }

    public function detailForItem(int $inboxItemId): ?array
    {
        $item = InboxItem::find($inboxItemId);
        if (!$item || $item->channel?->value !== Channel::Call->value) {
            return null;
        }

        $s = $this->loadSession($item);

        $direction = $s->direction ?? 'inbound';
        $answered = ($s->answered_at ?? null) !== null;
        $missed = $direction === 'inbound' && !$answered;
        $number = $direction === 'inbound' ? ($s->from_number ?? null) : ($s->to_number ?? null);
        $durationSec = (int) ($s->duration_seconds ?? 0);

        $directionLabel = ($direction === 'inbound' ? 'Eingehend' : 'Ausgehend')
            . ($missed ? ' · verpasst' : ($answered ? ' · angenommen' : ''));

        return [
            'channel'         => 'call',
            'channel_label'   => 'Anruf',
            'subject'         => $item->subject ?: ('Anruf ' . ($number ?: '')),
            'sender'          => $item->sender_label ?: ($number ?: 'Unbekannt'),
            'time'            => $this->timeLabel($item->received_at ? Carbon::parse($item->received_at) : null),
            'summary'         => null,
            'direction_label' => $directionLabel,
            'call_duration'   => $missed ? '0:00' : $this->fmtDuration($durationSec),
            'number'          => $number ?: '—',
            'recording'       => $this->recording($item),
            'context'         => $this->entities($item),
            'related'         => null,
        ];
    }

    protected function loadSession(InboxItem $item)
    {
        if (
            ($item->source_type ?? null) === 'user_connector_call_session'
            && Schema::hasTable('user_connector_call_sessions')
        ) {
            return DB::table('user_connector_call_sessions')->where('id', $item->source_id)->first();
        }

        return null;
    }

    /** Verlinkte Aufnahme (whisper via supplements) → Transcript-Segments. */
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
                    $this->fmtDuration((int) $seg->start_seconds),
                    $seg->speaker_label ?: 'Sprecher',
                    (string) $seg->text,
                ])
                ->all();

            return empty($segments) ? null : ['segments' => $segments];
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function entities(InboxItem $item): array
    {
        try {
            $links = app(InboxEntityLinkService::class)->linksFor($item);
        } catch (\Throwable $e) {
            return [];
        }

        return collect($links)->map(fn ($e) => [
            'id'    => $e['id'] ?? null,
            'label' => $e['name'] ?? '—',
            'path'  => $e['path'] ?? null,
            'icon'  => 'heroicon-o-share',
        ])->all();
    }

    protected function fmtDuration(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    protected function timeLabel(?Carbon $when): string
    {
        if (!$when) {
            return '—';
        }
        if ($when->isToday()) {
            return $when->format('H:i');
        }
        if ($when->isYesterday()) {
            return 'Gestern';
        }
        if ($when->diffInDays(now()) < 7) {
            return $when->locale('de')->isoFormat('dd');
        }

        return $when->locale('de')->isoFormat('D.M.');
    }
}
