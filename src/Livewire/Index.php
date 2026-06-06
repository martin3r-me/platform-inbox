<?php

namespace Platform\Inbox\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Enums\SubscriptionStatus;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxSenderSubscription;

class Index extends Component
{
    public string $channel = '';
    public string $search = '';

    protected $queryString = [
        'channel' => ['except' => ''],
        'search' => ['except' => ''],
    ];

    #[Computed]
    public function items()
    {
        $userId = auth()->id();

        $query = InboxItem::query()
            ->where('user_id', $userId)
            ->where('status', InboxItemStatus::New->value)
            ->notSnoozed()
            ->orderByDesc('received_at');

        if ($this->channel !== '') {
            $query->where('channel', $this->channel);
        }

        if ($this->search !== '') {
            $like = '%' . $this->search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('subject', 'like', $like)
                    ->orWhere('preview', 'like', $like)
                    ->orWhere('sender_identifier', 'like', $like)
                    ->orWhere('sender_label', 'like', $like);
            });
        }

        return $query->limit(200)->get();
    }

    /**
     * Map "{kind}:{identifier}" → ['is_vip' => bool, 'status' => SubscriptionStatus]
     * for all senders visible in the current item list. One query, then constant lookup.
     */
    #[Computed]
    public function senderMeta(): array
    {
        $pairs = $this->items
            ->map(fn ($i) => $i->sender_kind && $i->sender_identifier
                ? [$i->sender_kind, $i->sender_identifier]
                : null)
            ->filter()
            ->unique(fn ($p) => $p[0] . '|' . $p[1])
            ->values();

        if ($pairs->isEmpty()) {
            return [];
        }

        $query = InboxSenderSubscription::query()
            ->where('user_id', auth()->id())
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as $p) {
                    $q->orWhere(function ($qq) use ($p) {
                        $qq->where('sender_kind', $p[0])
                           ->where('sender_identifier', $p[1]);
                    });
                }
            });

        return $query->get()->mapWithKeys(fn ($s) => [
            $s->sender_kind . ':' . $s->sender_identifier => [
                'is_vip' => (bool) $s->is_vip,
                'status' => $s->status?->value,
            ],
        ])->all();
    }

    public function markDone(int $id): void
    {
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update(['status' => InboxItemStatus::Done->value, 'handled_at' => now()]);
        unset($this->items, $this->senderMeta);
    }

    public function ignore(int $id): void
    {
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update(['status' => InboxItemStatus::Ignored->value, 'handled_at' => now()]);
        unset($this->items, $this->senderMeta);
    }

    public function snooze(int $id, int $hours = 4): void
    {
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update([
                'status' => InboxItemStatus::Snoozed->value,
                'snoozed_until' => now()->addHours($hours),
            ]);
        unset($this->items, $this->senderMeta);
    }

    public function muteSender(int $id): void
    {
        $this->updateSenderSubscription($id, status: SubscriptionStatus::Muted);
    }

    public function unsubscribeSender(int $id): void
    {
        $this->updateSenderSubscription($id, status: SubscriptionStatus::Unsubscribed);
        // Also mark current item as ignored so it disappears from the open list.
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update(['status' => InboxItemStatus::Ignored->value, 'handled_at' => now()]);
        unset($this->items, $this->senderMeta);
    }

    public function toggleVip(int $id): void
    {
        $item = InboxItem::where('id', $id)->where('user_id', auth()->id())->first();
        if (!$item || !$item->sender_kind || !$item->sender_identifier) {
            return;
        }

        $sub = $this->ensureSubscription($item);
        $sub->update(['is_vip' => !$sub->is_vip]);
        unset($this->senderMeta);
    }

    protected function updateSenderSubscription(int $itemId, SubscriptionStatus $status): void
    {
        $item = InboxItem::where('id', $itemId)->where('user_id', auth()->id())->first();
        if (!$item || !$item->sender_kind || !$item->sender_identifier) {
            return;
        }

        $sub = $this->ensureSubscription($item);
        $sub->update(['status' => $status->value]);
        unset($this->senderMeta);
    }

    protected function ensureSubscription(InboxItem $item): InboxSenderSubscription
    {
        return InboxSenderSubscription::firstOrCreate(
            [
                'user_id' => $item->user_id,
                'sender_kind' => $item->sender_kind,
                'sender_identifier' => $item->sender_identifier,
            ],
            [
                'team_id' => $item->team_id,
                'status' => SubscriptionStatus::Subscribed->value,
                'is_vip' => false,
                'label' => $item->sender_label,
                'last_seen_at' => $item->received_at,
            ],
        );
    }

    public function render()
    {
        return view('inbox::livewire.index')
            ->layout('platform::layouts.app');
    }
}
