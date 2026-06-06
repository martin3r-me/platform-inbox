<?php

namespace Platform\Inbox\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Services\InboxSendService;

class Show extends Component
{
    public InboxItem $item;
    public string $composeBody = '';
    public string $composeSubject = '';
    public bool $closeOnSend = true;

    public ?string $sendFeedback = null;
    public bool $sendOk = false;

    public function mount(InboxItem $item): void
    {
        abort_unless($item->user_id === auth()->id(), 403);

        $this->item = $item;
        $this->composeSubject = $item->subject ? 'Re: ' . $item->subject : '';
    }

    #[Computed]
    public function canReply(): bool
    {
        return app(InboxSendService::class)->canReply($this->item);
    }

    public function markDone(): void
    {
        $this->item->update([
            'status' => InboxItemStatus::Done->value,
            'handled_at' => now(),
        ]);
        $this->item->refresh();
    }

    public function send(): void
    {
        $this->sendFeedback = null;

        $channelValue = $this->item->channel?->value;
        // Calls don't need a body — the dialer is the message.
        $needsBody = in_array($channelValue, ['mail', 'message'], true);
        if ($needsBody) {
            $this->validate([
                'composeBody' => 'required|string|min:1',
            ]);
        }

        $result = app(InboxSendService::class)->sendReply(
            $this->item,
            $this->composeSubject,
            $this->composeBody,
            auth()->user(),
        );

        $this->sendOk = $result['ok'];
        $this->sendFeedback = $result['message'];

        if ($result['ok'] && $this->closeOnSend) {
            $this->item->update([
                'status' => InboxItemStatus::Done->value,
                'handled_at' => now(),
            ]);
            $this->item->refresh();
            $this->composeBody = '';
        }
    }

    public function render()
    {
        return view('inbox::livewire.show')
            ->layout('platform::layouts.app');
    }
}
