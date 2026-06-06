<?php

namespace Platform\Inbox\Livewire;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Inbox\Enums\SubscriptionStatus;
use Platform\Inbox\Models\InboxSenderSubscription;

class SubscriptionIndex extends Component
{
    public string $filterStatus = '';
    public string $filterKind = '';
    public string $search = '';

    public array $newSubscription = [
        'sender_kind' => 'email',
        'sender_identifier' => '',
        'label' => '',
        'status' => 'subscribed',
    ];

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

    public function toggleVip(int $id): void
    {
        $sub = InboxSenderSubscription::where('id', $id)
            ->where('user_id', auth()->id())
            ->first();

        if (!$sub) {
            return;
        }
        $sub->update(['is_vip' => !$sub->is_vip]);
        unset($this->subscriptions);
    }

    public function addSubscription(): void
    {
        $this->validate([
            'newSubscription.sender_kind' => 'required|string|in:email,phone,teams',
            'newSubscription.sender_identifier' => 'required|string|min:1|max:255',
            'newSubscription.status' => 'required|string|in:subscribed,muted,unsubscribed',
            'newSubscription.label' => 'nullable|string|max:255',
        ]);

        $normalized = InboxSenderSubscription::normalize(
            $this->newSubscription['sender_identifier'],
            $this->newSubscription['sender_kind'],
        );

        if ($normalized === null) {
            $this->addError('newSubscription.sender_identifier', 'Bezeichner ungültig.');
            return;
        }

        InboxSenderSubscription::updateOrCreate(
            [
                'user_id' => auth()->id(),
                'sender_kind' => $this->newSubscription['sender_kind'],
                'sender_identifier' => $normalized,
            ],
            [
                'team_id' => auth()->user()->currentTeam->id,
                'status' => $this->newSubscription['status'],
                'label' => $this->newSubscription['label'] ?: null,
            ],
        );

        $this->newSubscription = [
            'sender_kind' => $this->newSubscription['sender_kind'],
            'sender_identifier' => '',
            'label' => '',
            'status' => 'subscribed',
        ];

        unset($this->subscriptions);
    }

    #[Computed]
    public function subscriptions()
    {
        $query = InboxSenderSubscription::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('is_vip')
            ->orderByDesc('last_seen_at')
            ->orderBy('sender_identifier');

        if ($this->filterStatus !== '') {
            $query->where('status', $this->filterStatus);
        }
        if ($this->filterKind !== '') {
            $query->where('sender_kind', $this->filterKind);
        }
        if ($this->search !== '') {
            $like = '%' . $this->search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('sender_identifier', 'like', $like)
                    ->orWhere('label', 'like', $like);
            });
        }

        return $query->limit(500)->get();
    }

    /**
     * Count of inbox_items per sender in the last 30 days.
     * Key: "{kind}:{identifier}" → int
     */
    #[Computed]
    public function volumesBySender(): array
    {
        $userId = auth()->id();
        $since = now()->subDays(30);

        return DB::table('inbox_items')
            ->where('user_id', $userId)
            ->where('received_at', '>=', $since)
            ->whereNotNull('sender_identifier')
            ->select('sender_kind', 'sender_identifier', DB::raw('COUNT(*) as cnt'))
            ->groupBy('sender_kind', 'sender_identifier')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->sender_kind . ':' . $r->sender_identifier => (int) $r->cnt])
            ->all();
    }

    public function render()
    {
        return view('inbox::livewire.subscription-index')
            ->layout('platform::layouts.app');
    }
}
