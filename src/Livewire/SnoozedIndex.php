<?php

namespace Platform\Inbox\Livewire;

use Carbon\CarbonImmutable;
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
            ->limit(500)
            ->get();
    }

    /**
     * Group snoozed items into Heute / Diese Woche / Später buckets,
     * each ordered by wake-up time ascending.
     */
    #[Computed]
    public function buckets(): array
    {
        $now = CarbonImmutable::now();
        $endOfToday = $now->endOfDay();
        $endOfWeek = $now->endOfWeek();

        $items = $this->items;

        return [
            [
                'key' => 'today',
                'label' => 'Heute',
                'icon' => 'heroicon-o-sun',
                'items' => $items->filter(fn ($i) => $i->snoozed_until && $i->snoozed_until <= $endOfToday)->values(),
            ],
            [
                'key' => 'week',
                'label' => 'Diese Woche',
                'icon' => 'heroicon-o-calendar-days',
                'items' => $items->filter(fn ($i) => $i->snoozed_until && $i->snoozed_until > $endOfToday && $i->snoozed_until <= $endOfWeek)->values(),
            ],
            [
                'key' => 'later',
                'label' => 'Später',
                'icon' => 'heroicon-o-archive-box',
                'items' => $items->filter(fn ($i) => $i->snoozed_until && $i->snoozed_until > $endOfWeek)->values(),
            ],
        ];
    }

    public function wakeUp(int $id): void
    {
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update([
                'status' => InboxItemStatus::New->value,
                'snoozed_until' => null,
            ]);
        unset($this->items, $this->buckets);
    }

    public function reschedule(int $id, int $hours): void
    {
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update(['snoozed_until' => now()->addHours($hours)]);
        unset($this->items, $this->buckets);
    }

    public function markDone(int $id): void
    {
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update([
                'status' => InboxItemStatus::Done->value,
                'handled_at' => now(),
                'snoozed_until' => null,
            ]);
        unset($this->items, $this->buckets);
    }

    public function render()
    {
        return view('inbox::livewire.snoozed-index')
            ->layout('platform::layouts.app');
    }
}
