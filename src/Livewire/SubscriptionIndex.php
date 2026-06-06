<?php

namespace Platform\Inbox\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Inbox\Enums\SubscriptionStatus;
use Platform\Inbox\Models\InboxSenderSubscription;

class SubscriptionIndex extends Component
{
    public string $filterStatus = '';

    public function setStatus(int $id, string $status): void
    {
        $allowed = array_map(fn ($c) => $c->value, SubscriptionStatus::cases());
        if (!in_array($status, $allowed, true)) {
            return;
        }

        InboxSenderSubscription::where('id', $id)
            ->where('user_id', auth()->id())
            ->update(['status' => $status]);

        unset($this->subscriptions);
    }

    #[Computed]
    public function subscriptions()
    {
        $query = InboxSenderSubscription::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('last_seen_at')
            ->orderBy('sender_identifier');

        if ($this->filterStatus !== '') {
            $query->where('status', $this->filterStatus);
        }

        return $query->limit(500)->get();
    }

    public function render()
    {
        return view('inbox::livewire.subscription-index')
            ->layout('platform::layouts.app');
    }
}
