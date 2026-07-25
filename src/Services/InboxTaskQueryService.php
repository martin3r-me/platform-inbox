<?php

namespace Platform\Inbox\Services;

use Illuminate\Support\Carbon;
use Platform\Inbox\Contracts\InboxTaskQueryContract;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;

/**
 * Aufgaben-Query für die persönliche Sicht (home). Task-Items kommen per
 * deliver() aus dem planner (Zuweisung an den Nutzer). Liste quellfrei; Detail
 * liefert Titel/Zuweiser/Beschreibung + Task-Referenz (für Deep-Link) + Knoten.
 */
class InboxTaskQueryService implements InboxTaskQueryContract
{
    public function listForUser(int $userId, int $teamId, int $limit = 25): array
    {
        $items = InboxItem::query()
            ->where('user_id', $userId)
            ->where('channel', Channel::Task->value)
            ->where('status', InboxItemStatus::New->value)
            ->where(function ($q) {
                $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })
            ->orderByDesc('received_at')
            ->limit($limit)
            ->get();

        return $items->map(fn (InboxItem $item) => [
            'id'      => (int) $item->id,
            'subject' => $item->subject ?: 'Aufgabe',
            'sender'  => $item->sender_label ?: 'Zugewiesen',
            'preview' => $item->preview,
            'time'    => $this->timeLabel($item->received_at ? Carbon::parse($item->received_at) : null),
            'unread'  => $item->status?->value === InboxItemStatus::New->value,
        ])->all();
    }

    public function detailForItem(int $inboxItemId): ?array
    {
        $item = InboxItem::find($inboxItemId);
        if (!$item || $item->channel?->value !== Channel::Task->value) {
            return null;
        }

        // Referenz auf die planner-Aufgabe (für den Deep-Link) — nur die ID, loose.
        $taskId = ($item->source_type === 'planner_task') ? (int) $item->source_id : null;

        return [
            'channel'       => 'task',
            'channel_label' => 'Aufgabe',
            'subject'       => $item->subject ?: 'Aufgabe',
            'sender'        => $item->sender_label ?: 'Zugewiesen',
            'time'          => $this->timeLabel($item->received_at ? Carbon::parse($item->received_at) : null),
            'summary'       => null,
            'body'          => $item->body ?: $item->preview,
            'task_id'       => $taskId,
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

        return collect($links)->map(fn ($e) => [
            'id'    => $e['id'] ?? null,
            'label' => $e['name'] ?? '—',
            'path'  => $e['path'] ?? null,
            'icon'  => 'heroicon-o-share',
        ])->all();
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
