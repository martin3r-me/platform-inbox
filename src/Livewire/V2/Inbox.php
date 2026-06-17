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

    /** smart | chronological — toggled via Shift+S; chronological is the
     *  default since the smart bucketing has been moved up into the quick-
     *  filter bar (Awaiting/Heute as explicit chips) and the new flat stream
     *  groups items by date — both make the implicit smart ordering harder
     *  to predict than a plain timeline. */
    #[Url(as: 'sort')]
    public string $sortMode = 'chronological';

    /** filter chips (channel/sender/entity) — null when empty */
    #[Url(as: 'ch')]
    public ?string $filterChannel = null;

    /** quick filter — null | 'today' | 'awaiting' | 'meeting' */
    #[Url(as: 'q')]
    public ?string $quickFilter = null;

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
            'channel' => $this->effectiveChannelFilter(),
        ]);
        return app(StreamProjector::class)
            ->project($userId, $this->bucket, $filters, $this->sortMode);
    }

    /**
     * Flat, date-bucketed item projection — primary V2 stream rendering.
     * Returns { groups, total, counts } so the quick-filter bar can show
     * live counters without a second query.
     */
    #[Computed]
    public function streamItems(): array
    {
        $userId = auth()->id();
        if (!$userId) {
            return ['groups' => [], 'total' => 0, 'counts' => []];
        }
        $filters = array_filter([
            'channel' => $this->effectiveChannelFilter(),
            'awaiting' => $this->quickFilter === 'awaiting' ?: null,
            'today' => $this->quickFilter === 'today' ?: null,
        ]);
        return app(StreamProjector::class)
            ->projectItems($userId, $this->bucket, $filters);
    }

    /**
     * 'meeting' is a quick-filter chip that maps onto the channel filter —
     * keeps the chip co-located with Today/Awaiting visually even though
     * under the hood it just narrows by channel.
     */
    protected function effectiveChannelFilter(): ?string
    {
        if ($this->quickFilter === 'meeting') {
            return 'meeting';
        }
        return $this->filterChannel;
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

    /**
     * Selection for the flat item stream — collapses senderKey + threadKey
     * derivation server-side so the view only deals with item ids.
     */
    public function selectItem(int $itemId): void
    {
        $row = $this->findItemRow($itemId);
        if (!$row) {
            return;
        }
        $this->selectThread($row['sender_key'], $row['thread_key']);
    }

    public function setQuickFilter(?string $key): void
    {
        $this->quickFilter = $this->quickFilter === $key ? null : $key;
        $this->senderKey = null;
        $this->threadKey = null;
    }

    /**
     * Look up an item across the projected groups — used by selectItem and
     * by keyboard navigation to walk the flat stream order.
     *
     * @return array<string,mixed>|null
     */
    protected function findItemRow(int $itemId): ?array
    {
        foreach (($this->streamItems['groups'] ?? []) as $group) {
            foreach ($group['items'] as $row) {
                if ((int) $row['id'] === $itemId) {
                    return $row;
                }
            }
        }
        return null;
    }

    /**
     * Flatten the date-bucketed stream into a single ordered id list so
     * j/k step linearly through the visible items.
     *
     * @return array<int, int>
     */
    protected function flatItemIds(): array
    {
        $ids = [];
        foreach (($this->streamItems['groups'] ?? []) as $group) {
            foreach ($group['items'] as $row) {
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
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
        unset($this->streamRows, $this->streamItems, $this->bucketCounts, $this->cockpitData);
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
        unset($this->streamRows, $this->streamItems, $this->bucketCounts, $this->cockpitData);
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
        unset($this->streamRows, $this->streamItems, $this->bucketCounts, $this->cockpitData);
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
            unset($this->streamRows, $this->streamItems, $this->bucketCounts, $this->cockpitData);
        }
    }

    /* --------------------------------------------------------------------
       Keyboard moves — Alpine calls these via $wire.method()
       -------------------------------------------------------------------- */

    /**
     * j/k navigation — walks the flat date-bucketed item stream linearly so
     * jumping never crosses an invisible sender boundary. Identifies the
     * current position by the active item's id (resolved from threadKey).
     */
    public function moveItem(int $direction): void
    {
        $ids = $this->flatItemIds();
        if (empty($ids)) {
            return;
        }

        $currentId = $this->currentItem()?->id;
        if ($currentId === null) {
            $this->selectItem($ids[0]);
            return;
        }

        $idx = array_search($currentId, $ids, true);
        if ($idx === false) {
            $this->selectItem($ids[0]);
            return;
        }

        $next = max(0, min(count($ids) - 1, $idx + $direction));
        $this->selectItem($ids[$next]);
    }

    /**
     * Sender-level nav stays available for keyboard users (Shift+J/K) that
     * still think in senders — walks unique sender_keys in stream order.
     */
    public function moveSender(int $direction): void
    {
        $seen = [];
        $keys = [];
        foreach (($this->streamItems['groups'] ?? []) as $group) {
            foreach ($group['items'] as $row) {
                $k = $row['sender_key'];
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $keys[] = $k;
            }
        }
        if (empty($keys)) {
            return;
        }
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

    /**
     * Compatibility alias — moveItem is the new j/k handler. Older keymap
     * code that still calls moveThread keeps working by stepping through
     * items, which is the same effect now that the stream is flat.
     */
    public function moveThread(int $direction): void
    {
        $this->moveItem($direction);
    }

    public function render(): View
    {
        return view('inbox::livewire.v2.inbox')
            ->layout('platform::layouts.app');
    }
}
