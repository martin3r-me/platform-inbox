<?php

namespace Platform\Inbox\Livewire\V2;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Services\InboxSendService;
use Platform\Inbox\Services\V2\CockpitDataLoader;
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

    /* ----- reply composer state -------- not URL-bound, transient only ----- */
    public bool $replyOpen = false;
    public string $replySubject = '';
    public string $replyBody = '';
    public ?string $replyFeedback = null;
    public bool $replyOk = false;
    public bool $closeOnSend = true;

    /* ----- snooze picker state ------------------------------------------- */
    public bool $snoozePickerOpen = false;

    /* ----- keyboard help overlay ----------------------------------------- */
    public bool $helpOpen = false;

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
    public function streamRows(): array
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

    /**
     * Resolves the latest InboxItem represented by the current threadKey
     * (via the stream rows so we don't re-query when selection moves).
     */
    protected function currentItem(): ?InboxItem
    {
        if (!$this->threadKey || !$this->senderKey) {
            return null;
        }
        $rows = $this->streamRows;
        $row = collect($rows)->first(
            fn ($r) => $this->senderKey === $r['sender_kind'] . '|' . $r['sender_identifier'],
        );
        if (!$row) {
            return null;
        }
        $thread = collect($row['threads'])->first(function ($t) {
            $key = $t['thread_key'] ?: ('item-' . $t['latest_item_id']);
            return $key === $this->threadKey;
        });
        if (!$thread) {
            return null;
        }
        return InboxItem::query()
            ->where('id', $thread['latest_item_id'])
            ->where('user_id', auth()->id())
            ->first();
    }

    #[Computed]
    public function cockpitData(): ?array
    {
        $item = $this->currentItem();
        if (!$item) {
            return null;
        }
        return app(CockpitDataLoader::class)->load($item);
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
       Verbs — operate on the currently selected thread / item
       -------------------------------------------------------------------- */

    public function markDone(): void
    {
        $item = $this->currentItem();
        if (!$item) {
            return;
        }
        $item->update([
            'status' => InboxItemStatus::Done->value,
            'handled_at' => now(),
        ]);
        // Move to the next thread so flow is preserved.
        $this->threadKey = null;
        $this->moveThread(1);
        unset($this->streamRows, $this->bucketCounts, $this->cockpitData);
    }

    public function snooze(int $hours = 4): void
    {
        $item = $this->currentItem();
        if (!$item) {
            return;
        }
        $item->update([
            'status' => InboxItemStatus::Snoozed->value,
            'snoozed_until' => now()->addHours(max(1, $hours)),
        ]);
        $this->snoozePickerOpen = false;
        $this->threadKey = null;
        $this->moveThread(1);
        unset($this->streamRows, $this->bucketCounts, $this->cockpitData);
    }

    /**
     * Smart-time snooze targets — named preset → resolved Carbon instant.
     * Centralised so the picker and the keymap agree on labels.
     */
    public function snoozePresets(): array
    {
        return [
            ['key' => '1h',         'label' => 'In 1 Stunde',      'at' => now()->addHour()],
            ['key' => '4h',         'label' => 'In 4 Stunden',     'at' => now()->addHours(4)],
            ['key' => 'tonight',    'label' => 'Heute Abend (19:00)', 'at' => now()->setTime(19, 0)->isPast() ? now()->addDay()->setTime(19, 0) : now()->setTime(19, 0)],
            ['key' => 'tomorrow',   'label' => 'Morgen früh (08:00)', 'at' => now()->addDay()->setTime(8, 0)],
            ['key' => 'next_week',  'label' => 'Nächste Woche (Mo 08:00)', 'at' => now()->next('Monday')->setTime(8, 0)],
            ['key' => 'next_month', 'label' => 'Nächsten Monat',   'at' => now()->addMonth()->startOfDay()->setTime(8, 0)],
        ];
    }

    public function snoozeUntil(string $key): void
    {
        $item = $this->currentItem();
        if (!$item) {
            return;
        }
        $preset = collect($this->snoozePresets())->firstWhere('key', $key);
        if (!$preset) {
            return;
        }
        $item->update([
            'status' => InboxItemStatus::Snoozed->value,
            'snoozed_until' => $preset['at'],
        ]);
        $this->snoozePickerOpen = false;
        $this->threadKey = null;
        $this->moveThread(1);
        unset($this->streamRows, $this->bucketCounts, $this->cockpitData);
    }

    public function toggleSnoozePicker(): void
    {
        $this->snoozePickerOpen = !$this->snoozePickerOpen;
    }

    public function toggleHelp(): void
    {
        $this->helpOpen = !$this->helpOpen;
    }

    /* --------------------------------------------------------------------
       Reply composer — uses the existing InboxSendService so mail goes out
       through Outlook /reply (preserves thread) and chats land in Teams.
       -------------------------------------------------------------------- */

    public function openReply(): void
    {
        $item = $this->currentItem();
        if (!$item) {
            return;
        }
        // Mail uses /reply on the Outlook side — subject stays implicit. We
        // still prefill it for the textarea so the user sees the context.
        $subject = $item->subject ?: '';
        if (!str_starts_with(strtolower($subject), 're:') && $subject !== '') {
            $subject = 'Re: ' . $subject;
        }
        $this->replySubject = $subject;
        $this->replyBody = '';
        $this->replyFeedback = null;
        $this->replyOk = false;
        $this->replyOpen = true;
    }

    public function closeReply(): void
    {
        $this->replyOpen = false;
        $this->replyBody = '';
        $this->replySubject = '';
        $this->replyFeedback = null;
    }

    public function sendReply(): void
    {
        $item = $this->currentItem();
        if (!$item) {
            return;
        }
        $body = trim($this->replyBody);
        if ($body === '') {
            $this->replyFeedback = 'Bitte einen Text eingeben.';
            $this->replyOk = false;
            return;
        }

        $result = app(InboxSendService::class)->sendReply(
            $item,
            $this->replySubject,
            $body,
            auth()->user(),
        );

        $this->replyOk = (bool) ($result['ok'] ?? false);
        $this->replyFeedback = $result['message'] ?? null;

        if ($this->replyOk && $this->closeOnSend) {
            $item->update([
                'status' => InboxItemStatus::Done->value,
                'handled_at' => now(),
                'awaiting_reply_since' => now(),
            ]);
            $this->replyOpen = false;
            $this->replyBody = '';
            $this->replySubject = '';
            $this->threadKey = null;
            $this->moveThread(1);
            unset($this->streamRows, $this->bucketCounts, $this->cockpitData);
        }
    }

    /* --------------------------------------------------------------------
       Keyboard moves — Alpine calls these via $wire.method()
       -------------------------------------------------------------------- */

    public function moveSender(int $direction): void
    {
        $rows = $this->streamRows;
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
        $rows = $this->streamRows;
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
