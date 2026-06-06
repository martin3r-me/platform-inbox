<?php

namespace Platform\Inbox\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Inbox\Enums\SubscriptionStatus;
use Platform\Inbox\Models\InboxSenderSubscription;

/**
 * Read-only list of unsubscribed senders for a given user.
 * Embed from user-connectors UI:
 *   <livewire:inbox.embedded-subscription-list :user-id="auth()->id()" />
 *
 * Inbox owns the data; user-connectors just shows the window into it.
 */
class EmbeddedSubscriptionList extends Component
{
    public int $userId;
    public string $kind = '';

    #[Computed]
    public function unsubscribed()
    {
        $query = InboxSenderSubscription::query()
            ->where('user_id', $this->userId)
            ->where('status', SubscriptionStatus::Unsubscribed->value)
            ->orderBy('sender_kind')
            ->orderBy('sender_identifier');

        if ($this->kind !== '') {
            $query->where('sender_kind', $this->kind);
        }

        return $query->get();
    }

    public function resubscribe(int $id): void
    {
        InboxSenderSubscription::where('id', $id)
            ->where('user_id', $this->userId)
            ->update(['status' => SubscriptionStatus::Subscribed->value]);

        unset($this->unsubscribed);
    }

    public function render()
    {
        return view('inbox::livewire.embedded-subscription-list');
    }
}
