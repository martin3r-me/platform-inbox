<?php

namespace Platform\Inbox\Services;

use Illuminate\Support\Carbon;
use Platform\Inbox\Contracts\InboxTicketQueryContract;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;

/**
 * Ticket-Query für die persönliche Sicht (home). Ticket-Items kommen per deliver()
 * aus dem helpdesk (Zuweisung an den Nutzer). Liste quellfrei; Detail: Titel/
 * Zuweiser/Beschreibung + Ticket-Referenz (Deep-Link) + Org-Knoten-Kontext.
 */
class InboxTicketQueryService implements InboxTicketQueryContract
{
    public function listForUser(int $userId, int $teamId, int $limit = 25): array
    {
        $items = InboxItem::query()
            ->where('user_id', $userId)
            ->where('channel', Channel::Ticket->value)
            ->where('status', InboxItemStatus::New->value)
            ->where(function ($q) {
                $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })
            ->orderByDesc('received_at')
            ->limit($limit)
            ->get();

        return $items->map(fn (InboxItem $item) => [
            'id'      => (int) $item->id,
            'subject' => $item->subject ?: 'Ticket',
            'sender'  => $item->sender_label ?: 'Zugewiesen',
            'preview' => $item->preview,
            'time'    => $this->timeLabel($item->received_at ? Carbon::parse($item->received_at) : null),
            'unread'  => $item->status?->value === InboxItemStatus::New->value,
        ])->all();
    }

    public function detailForItem(int $inboxItemId): ?array
    {
        $item = InboxItem::find($inboxItemId);
        if (!$item || $item->channel?->value !== Channel::Ticket->value) {
            return null;
        }

        $ticketId = ($item->source_type === 'helpdesk_ticket') ? (int) $item->source_id : null;

        return [
            'channel'       => 'ticket',
            'channel_label' => 'Ticket',
            'subject'       => $item->subject ?: 'Ticket',
            'sender'        => $item->sender_label ?: 'Zugewiesen',
            'time'          => $this->timeLabel($item->received_at ? Carbon::parse($item->received_at) : null),
            'summary'       => null,
            'body'          => $item->body ?: $item->preview,
            'ticket_id'     => $ticketId,
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
