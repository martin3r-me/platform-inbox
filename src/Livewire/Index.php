<?php

namespace Platform\Inbox\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;

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

    public function markDone(int $id): void
    {
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update(['status' => InboxItemStatus::Done->value, 'handled_at' => now()]);
        unset($this->items);
    }

    public function ignore(int $id): void
    {
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update(['status' => InboxItemStatus::Ignored->value, 'handled_at' => now()]);
        unset($this->items);
    }

    public function snooze(int $id, int $hours = 4): void
    {
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update([
                'status' => InboxItemStatus::Snoozed->value,
                'snoozed_until' => now()->addHours($hours),
            ]);
        unset($this->items);
    }

    public function render()
    {
        return view('inbox::livewire.index')
            ->layout('platform::layouts.app');
    }
}
