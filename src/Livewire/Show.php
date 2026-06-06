<?php

namespace Platform\Inbox\Livewire;

use Livewire\Component;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;

class Show extends Component
{
    public InboxItem $item;
    public string $composeBody = '';
    public string $composeSubject = '';

    public function mount(InboxItem $item): void
    {
        abort_unless($item->user_id === auth()->id(), 403);

        $this->item = $item;
        $this->composeSubject = $item->subject ? 'Re: ' . $item->subject : '';
    }

    public function markDone(): void
    {
        $this->item->update([
            'status' => InboxItemStatus::Done->value,
            'handled_at' => now(),
        ]);
    }

    public function send(): void
    {
        // Skeleton — actual send routes through user-connectors send-tools:
        //   - mail → microsoft365.mail.send
        //   - message (sms) → sipgate.sms.send / ringcentral.sms.send
        //   - call (callback) → sipgate.calls.initiate
        // That guarantees the message appears in the user's original service
        // (Outlook Sent Items, Teams thread, Sipgate call log) — no parallel state.
        $this->dispatch('inbox-compose-send', itemId: $this->item->id);
    }

    public function render()
    {
        return view('inbox::livewire.show')
            ->layout('platform::layouts.app');
    }
}
