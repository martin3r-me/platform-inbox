<?php

namespace Platform\Inbox\Services;

use Illuminate\Support\Carbon;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;

/**
 * Gemeinsame Basis für die Producer-Push-Kanäle „mir zugewiesen" (Aufgabe, Ticket
 * — künftig weitere). Alle folgen demselben Muster: das Item kommt per
 * `Inbox::deliver()` rein, die Liste ist quellfrei, das Detail liefert
 * Titel/Zuweiser/Beschreibung + eine Referenz-ID (Deep-Link) + Org-Knoten-Kontext.
 *
 * Konkrete Services parametrisieren nur Kanal, Label, Source-Morph und Ref-Key.
 */
abstract class AbstractInboxAssignedQueryService
{
    /** Der Kanal dieses Producers (Channel::Task / Channel::Ticket / …). */
    abstract protected function channel(): Channel;

    /** Anzeige-Label + Default-Betreff („Aufgabe" / „Ticket"). */
    abstract protected function label(): string;

    /** Morph-Alias des Ursprungs-Modells (z. B. 'planner_task' / 'helpdesk_ticket'). */
    abstract protected function sourceMorph(): string;

    /** Name des Referenz-Felds im Detail (z. B. 'task_id' / 'ticket_id'). */
    abstract protected function refKey(): string;

    public function listForUser(int $userId, int $teamId, int $limit = 25): array
    {
        $items = InboxItem::query()
            ->where('user_id', $userId)
            ->where('channel', $this->channel()->value)
            ->where('status', InboxItemStatus::New->value)
            ->where(function ($q) {
                $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })
            ->orderByDesc('received_at')
            ->limit($limit)
            ->get();

        return $items->map(fn (InboxItem $item) => [
            'id'      => (int) $item->id,
            'subject' => $item->subject ?: $this->label(),
            'sender'  => $item->sender_label ?: 'Zugewiesen',
            'preview' => $item->preview,
            'time'    => $this->timeLabel($item->received_at ? Carbon::parse($item->received_at) : null),
            'unread'  => $item->status?->value === InboxItemStatus::New->value,
        ])->all();
    }

    public function detailForItem(int $inboxItemId): ?array
    {
        $item = InboxItem::find($inboxItemId);
        if (!$item || $item->channel?->value !== $this->channel()->value) {
            return null;
        }

        $refId = ($item->source_type === $this->sourceMorph()) ? (int) $item->source_id : null;

        return [
            'channel'        => $this->channel()->value,
            'channel_label'  => $this->label(),
            'subject'        => $item->subject ?: $this->label(),
            'sender'         => $item->sender_label ?: 'Zugewiesen',
            'time'           => $this->timeLabel($item->received_at ? Carbon::parse($item->received_at) : null),
            'summary'        => null,
            'body'           => $item->body ?: $item->preview,
            $this->refKey()  => $refId,
            'context'        => $this->entities($item),
            'related'        => null,
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
