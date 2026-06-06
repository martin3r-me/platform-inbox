<?php

namespace Platform\Inbox\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;

class SnoozedIndex extends Component
{
    #[Computed]
    public function items()
    {
        return InboxItem::query()
            ->where('user_id', auth()->id())
            ->where('status', InboxItemStatus::Snoozed->value)
            ->orderBy('snoozed_until')
            ->limit(200)
            ->get();
    }

    public function wakeUp(int $id): void
    {
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update([
                'status' => InboxItemStatus::New->value,
                'snoozed_until' => null,
            ]);
        unset($this->items);
    }

    public function render()
    {
        return view('inbox::livewire.snoozed-index')
            ->layout('platform::layouts.app');
    }
}
