<?php

namespace Platform\Inbox\Livewire\V2;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Inbox\Services\V2\SmartBucketService;
use Platform\Inbox\Services\V2\StreamProjector;

/**
 * V2 Inbox — one root component, no event chatter. Holds the entire UI state
 * (bucket, selection, sort mode, expand) and renders the three columns via
 * partials. Keyboard nav lives in Alpine and reaches in through Livewire
 * methods — see resources/js/inbox-v2-keymap.js.
 *
 * Selection model:
 *   senderKey  = "<sender_kind>|<sender_identifier>"  (or null = no selection)
 *   threadKey  = thread_key from item OR "item-<id>"  (for thread-less items)
 *
 * When senderKey is set but threadKey is null → SenderOverview cockpit.
 * When threadKey is set → channel-aware Thread cockpit (built in layer d–g).
 */
class Inbox extends Component
{
    #[Url(as: 'b')]
    public string $bucket = SmartBucketService::DEFAULT_BUCKET;

    #[Url(as: 's')]
    public ?string $senderKey = null;

    #[Url(as: 't')]
    public ?string $threadKey = null;

    public ?string $expandedSenderKey = null;

    /** smart | chronological — toggled via Shift+S */
    #[Url(as: 'sort')]
    public string $sortMode = 'smart';

    /** filter chips (channel/sender/entity) — null when empty */
    #[Url(as: 'ch')]
    public ?string $filterChannel = null;

    public function mount(): void
    {
        // If the user landed on a sender deep-link, auto-expand its threads
        // so the stream visually leads the eye to the selection.
        if ($this->senderKey) {
            $this->expandedSenderKey = $this->senderKey;
        }
    }

    #[Computed]
    public function bucketDefs(): array
    {
        return app(SmartBucketService::class)->definitions();
    }

    #[Computed]
    public function bucketCounts(): array
    {
        $userId = auth()->id();
        if (!$userId) {
            return [];
        }
        return app(SmartBucketService::class)->counts($userId);
    }

    #[Computed]
    public function stream(): array
    {
        $userId = auth()->id();
        if (!$userId) {
            return [];
        }
        $filters = array_filter([
            'channel' => $this->filterChannel,
        ]);
        return app(StreamProjector::class)
            ->project($userId, $this->bucket, $filters, $this->sortMode);
    }

    #[Computed]
    public function cockpitMode(): string
    {
        if ($this->threadKey) {
            return 'thread';
        }
        if ($this->senderKey) {
            return 'sender-overview';
        }
        return 'empty';
    }

    /* --------------------------------------------------------------------
       Selection / navigation
       -------------------------------------------------------------------- */

    public function setBucket(string $key): void
    {
        $this->bucket = $key;
        $this->senderKey = null;
        $this->threadKey = null;
        $this->expandedSenderKey = null;
    }

    public function selectSender(string $key): void
    {
        $this->senderKey = $key;
        $this->threadKey = null;
        $this->expandedSenderKey = $key;
    }

    public function selectThread(string $senderKey, string $threadKey): void
    {
        $this->senderKey = $senderKey;
        $this->threadKey = $threadKey;
        $this->expandedSenderKey = $senderKey;
    }

    public function toggleExpand(string $senderKey): void
    {
        $this->expandedSenderKey = $this->expandedSenderKey === $senderKey ? null : $senderKey;
    }

    public function clearSelection(): void
    {
        if ($this->threadKey) {
            $this->threadKey = null;
            return;
        }
        if ($this->senderKey) {
            $this->senderKey = null;
            $this->expandedSenderKey = null;
            return;
        }
    }

    public function toggleSort(): void
    {
        $this->sortMode = $this->sortMode === 'smart' ? 'chronological' : 'smart';
    }

    /* --------------------------------------------------------------------
       Keyboard moves — Alpine calls these via $wire.method()
       -------------------------------------------------------------------- */

    public function moveSender(int $direction): void
    {
        $rows = $this->stream;
        if (empty($rows)) {
            return;
        }
        $keys = array_map(fn ($r) => $r['sender_kind'] . '|' . $r['sender_identifier'], $rows);
        if ($this->senderKey === null) {
            $this->selectSender($keys[0]);
            return;
        }
        $idx = array_search($this->senderKey, $keys, true);
        if ($idx === false) {
            $this->selectSender($keys[0]);
            return;
        }
        $next = max(0, min(count($keys) - 1, $idx + $direction));
        $this->selectSender($keys[$next]);
    }

    public function moveThread(int $direction): void
    {
        $rows = $this->stream;
        $row = collect($rows)->first(
            fn ($r) => $this->senderKey === $r['sender_kind'] . '|' . $r['sender_identifier'],
        );
        if (!$row || empty($row['threads'])) {
            return;
        }
        $keys = array_map(
            fn ($t) => $t['thread_key'] ?: ('item-' . $t['latest_item_id']),
            $row['threads'],
        );
        if ($this->threadKey === null) {
            $this->selectThread($this->senderKey, $keys[0]);
            return;
        }
        $idx = array_search($this->threadKey, $keys, true);
        if ($idx === false) {
            $this->selectThread($this->senderKey, $keys[0]);
            return;
        }
        $next = max(0, min(count($keys) - 1, $idx + $direction));
        $this->selectThread($this->senderKey, $keys[$next]);
    }

    public function render(): View
    {
        return view('inbox::livewire.v2.inbox')
            ->layout('platform::layouts.app');
    }
}
