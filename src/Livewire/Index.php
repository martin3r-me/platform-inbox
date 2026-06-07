<?php

namespace Platform\Inbox\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Enums\SubscriptionStatus;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemHandoff;
use Platform\Inbox\Models\InboxSenderSubscription;
use Platform\Inbox\Services\InboxEntityLinkService;
use Platform\Inbox\Services\InboxHandoffService;
use Platform\Inbox\Services\InboxRuleEngine;

class Index extends Component
{
    public string $channel = '';
    public string $search = '';

    /** ID of the row currently showing the inline entity picker; null = none open. */
    public ?int $entityPickerForItem = null;
    public string $entitySearch = '';
    public bool $alsoCreateRule = false;

    protected $queryString = [
        'channel' => ['except' => ''],
        'search' => ['except' => ''],
    ];

    #[Computed]
    public function items()
    {
        $userId = auth()->id();

        $query = InboxItem::query()
            ->where('user_id', $userId)
            ->where('status', InboxItemStatus::New->value)
            ->notSnoozed()
            ->orderByDesc('received_at');

        if ($this->channel !== '') {
            $query->where('channel', $this->channel);
        }

        if ($this->search !== '') {
            $like = '%' . $this->search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('subject', 'like', $like)
                    ->orWhere('preview', 'like', $like)
                    ->orWhere('sender_identifier', 'like', $like)
                    ->orWhere('sender_label', 'like', $like);
            });
        }

        return $query->limit(200)->get();
    }

    /**
     * Entities linked to each visible item, keyed by item_id.
     * Empty array when the Organization module is not installed.
     * @return array<int, array<int, array{id:int, name:string, type:string|null, code:string|null}>>
     */
    #[Computed]
    public function entityLinksByItem(): array
    {
        $ids = $this->items->pluck('id')->all();
        if (empty($ids)) {
            return [];
        }
        return app(InboxEntityLinkService::class)->linksForItems($ids);
    }

    /**
     * Map "{kind}:{identifier}" → ['is_vip' => bool, 'status' => SubscriptionStatus]
     * for all senders visible in the current item list. One query, then constant lookup.
     */
    #[Computed]
    public function senderMeta(): array
    {
        $pairs = $this->items
            ->map(fn ($i) => $i->sender_kind && $i->sender_identifier
                ? [$i->sender_kind, $i->sender_identifier]
                : null)
            ->filter()
            ->unique(fn ($p) => $p[0] . '|' . $p[1])
            ->values();

        if ($pairs->isEmpty()) {
            return [];
        }

        $query = InboxSenderSubscription::query()
            ->where('user_id', auth()->id())
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as $p) {
                    $q->orWhere(function ($qq) use ($p) {
                        $qq->where('sender_kind', $p[0])
                           ->where('sender_identifier', $p[1]);
                    });
                }
            });

        return $query->get()->mapWithKeys(fn ($s) => [
            $s->sender_kind . ':' . $s->sender_identifier => [
                'is_vip' => (bool) $s->is_vip,
                'status' => $s->status?->value,
            ],
        ])->all();
    }

    /**
     * Item-level handoffs (planner_task / helpdesk_ticket) for the currently
     * visible items, keyed by item_id → kind → handoff row. Used to show
     * "Task #42 angelegt"-badges on the row instead of a fresh action button.
     */
    #[Computed]
    public function itemHandoffsByItem(): array
    {
        $ids = $this->items->pluck('id')->all();
        if (empty($ids)) {
            return [];
        }
        return InboxItemHandoff::query()
            ->whereIn('inbox_item_id', $ids)
            ->whereNull('enrichment_id')
            ->whereNull('action_item_index')
            ->get()
            ->groupBy('inbox_item_id')
            ->map(fn ($group) => $group->keyBy('kind')->all())
            ->all();
    }

    #[Computed]
    public function plannerAvailable(): bool
    {
        return app(InboxHandoffService::class)->plannerAvailable();
    }

    #[Computed]
    public function helpdeskAvailable(): bool
    {
        return app(InboxHandoffService::class)->helpdeskAvailable();
    }

    public function openEntityPicker(int $itemId): void
    {
        $this->entityPickerForItem = $itemId;
        $this->entitySearch = '';
        $this->alsoCreateRule = false;
    }

    public function closeEntityPicker(): void
    {
        $this->entityPickerForItem = null;
        $this->entitySearch = '';
        $this->alsoCreateRule = false;
    }

    /**
     * Entity-search results for the currently-expanded row, with already-linked
     * entities filtered out so the user can't double-link.
     */
    #[Computed]
    public function entitySearchResults(): array
    {
        if ($this->entityPickerForItem === null || trim($this->entitySearch) === '') {
            return [];
        }
        $item = $this->items->firstWhere('id', $this->entityPickerForItem);
        if (!$item) {
            return [];
        }
        $alreadyLinked = array_map(fn ($e) => $e['id'], $this->entityLinksByItem[$item->id] ?? []);
        $results = app(InboxEntityLinkService::class)->search($this->entitySearch, $item->team_id);
        return array_values(array_filter($results, fn ($r) => !in_array($r['id'], $alreadyLinked, true)));
    }

    public function linkEntityFromRow(int $itemId, int $entityId): void
    {
        $item = InboxItem::where('id', $itemId)->where('user_id', auth()->id())->first();
        if (!$item) {
            return;
        }
        if (!app(InboxEntityLinkService::class)->link($item, $entityId)) {
            return;
        }
        if ($this->alsoCreateRule && $item->sender_identifier) {
            try {
                app(InboxRuleEngine::class)->quickRuleFromManualLink($item, $entityId);
            } catch (\Throwable $e) {
                \Log::warning('Inbox: quick rule from row failed', ['error' => $e->getMessage()]);
            }
        }
        $this->closeEntityPicker();
        unset($this->items, $this->entityLinksByItem);
    }

    public function unlinkEntityFromRow(int $itemId, int $entityId): void
    {
        $item = InboxItem::where('id', $itemId)->where('user_id', auth()->id())->first();
        if (!$item) {
            return;
        }
        app(InboxEntityLinkService::class)->unlink($item, $entityId);
        unset($this->entityLinksByItem);
    }

    public function handoffRowToPlanner(int $itemId): void
    {
        $item = InboxItem::where('id', $itemId)->where('user_id', auth()->id())->first();
        if (!$item) {
            return;
        }
        app(InboxHandoffService::class)->itemToPlannerTask($item, auth()->id());
        unset($this->itemHandoffsByItem);
    }

    public function handoffRowToHelpdesk(int $itemId): void
    {
        $item = InboxItem::where('id', $itemId)->where('user_id', auth()->id())->first();
        if (!$item) {
            return;
        }
        app(InboxHandoffService::class)->itemToHelpdeskTicket($item, auth()->id());
        unset($this->itemHandoffsByItem);
    }

    public function markDone(int $id): void
    {
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update(['status' => InboxItemStatus::Done->value, 'handled_at' => now()]);
        unset($this->items, $this->senderMeta);
    }

    public function ignore(int $id): void
    {
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update(['status' => InboxItemStatus::Ignored->value, 'handled_at' => now()]);
        unset($this->items, $this->senderMeta);
    }

    public function snooze(int $id, int $hours = 4): void
    {
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update([
                'status' => InboxItemStatus::Snoozed->value,
                'snoozed_until' => now()->addHours($hours),
            ]);
        unset($this->items, $this->senderMeta);
    }

    public function muteSender(int $id): void
    {
        $this->updateSenderSubscription($id, status: SubscriptionStatus::Muted);
    }

    public function unsubscribeSender(int $id): void
    {
        $this->updateSenderSubscription($id, status: SubscriptionStatus::Unsubscribed);
        // Also mark current item as ignored so it disappears from the open list.
        InboxItem::where('id', $id)
            ->where('user_id', auth()->id())
            ->update(['status' => InboxItemStatus::Ignored->value, 'handled_at' => now()]);
        unset($this->items, $this->senderMeta);
    }

    public function toggleVip(int $id): void
    {
        $item = InboxItem::where('id', $id)->where('user_id', auth()->id())->first();
        if (!$item || !$item->sender_kind || !$item->sender_identifier) {
            return;
        }

        $sub = $this->ensureSubscription($item);
        $sub->update(['is_vip' => !$sub->is_vip]);
        unset($this->senderMeta);
    }

    protected function updateSenderSubscription(int $itemId, SubscriptionStatus $status): void
    {
        $item = InboxItem::where('id', $itemId)->where('user_id', auth()->id())->first();
        if (!$item || !$item->sender_kind || !$item->sender_identifier) {
            return;
        }

        $sub = $this->ensureSubscription($item);
        $sub->update(['status' => $status->value]);
        unset($this->senderMeta);
    }

    protected function ensureSubscription(InboxItem $item): InboxSenderSubscription
    {
        return InboxSenderSubscription::firstOrCreate(
            [
                'user_id' => $item->user_id,
                'sender_kind' => $item->sender_kind,
                'sender_identifier' => $item->sender_identifier,
            ],
            [
                'team_id' => $item->team_id,
                'status' => SubscriptionStatus::Subscribed->value,
                'is_vip' => false,
                'label' => $item->sender_label,
                'last_seen_at' => $item->received_at,
            ],
        );
    }

    public function render()
    {
        return view('inbox::livewire.index')
            ->layout('platform::layouts.app');
    }
}
