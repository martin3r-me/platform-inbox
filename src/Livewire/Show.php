<?php

namespace Platform\Inbox\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Services\InboxEntityLinkService;
use Platform\Inbox\Services\InboxSendService;

class Show extends Component
{
    public InboxItem $item;
    public string $composeBody = '';
    public string $composeSubject = '';
    public bool $closeOnSend = true;

    public ?string $sendFeedback = null;
    public bool $sendOk = false;

    public string $entitySearch = '';

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

    #[Computed]
    public function entityLinkingEnabled(): bool
    {
        return app(InboxEntityLinkService::class)->enabled();
    }

    #[Computed]
    public function linkedEntities(): array
    {
        return app(InboxEntityLinkService::class)->linksFor($this->item);
    }

    #[Computed]
    public function entitySuggestion(): ?array
    {
        $suggest = app(InboxEntityLinkService::class)->suggestForItem($this->item);
        if (!$suggest) {
            return null;
        }
        $linkedIds = array_map(fn ($e) => $e['id'], $this->linkedEntities);
        if (in_array($suggest['id'], $linkedIds, true)) {
            return null;
        }
        return $suggest;
    }

    #[Computed]
    public function entitySearchResults(): array
    {
        if (trim($this->entitySearch) === '') {
            return [];
        }
        $linkedIds = array_map(fn ($e) => $e['id'], $this->linkedEntities);
        $results = app(InboxEntityLinkService::class)->search(
            $this->entitySearch,
            $this->item->team_id,
        );
        return array_values(array_filter($results, fn ($r) => !in_array($r['id'], $linkedIds, true)));
    }

    public function linkEntity(int $entityId): void
    {
        if (app(InboxEntityLinkService::class)->link($this->item, $entityId)) {
            $this->entitySearch = '';
            unset($this->linkedEntities, $this->entitySearchResults, $this->entitySuggestion);
        }
    }

    public function unlinkEntity(int $entityId): void
    {
        if (app(InboxEntityLinkService::class)->unlink($this->item, $entityId)) {
            unset($this->linkedEntities, $this->entitySearchResults, $this->entitySuggestion);
        }
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
