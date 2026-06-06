<?php

namespace Platform\Inbox\Livewire\Rules;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Inbox\Models\InboxLinkRule;

class Index extends Component
{
    public string $search = '';

    public function toggleActive(int $id): void
    {
        $rule = InboxLinkRule::where('id', $id)->where('user_id', auth()->id())->first();
        if ($rule) {
            $rule->update(['is_active' => !$rule->is_active]);
            unset($this->rules);
        }
    }

    public function deleteRule(int $id): void
    {
        InboxLinkRule::where('id', $id)->where('user_id', auth()->id())->delete();
        unset($this->rules);
    }

    #[Computed]
    public function rules()
    {
        $query = InboxLinkRule::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('is_active')
            ->orderBy('priority')
            ->orderByDesc('matched_count');

        if ($this->search !== '') {
            $like = '%' . $this->search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('sender_identifier', 'like', $like)
                    ->orWhere('sender_pattern', 'like', $like)
                    ->orWhere('subject_pattern', 'like', $like);
            });
        }

        return $query->limit(200)->get();
    }

    /**
     * For each rule's first target entity, fetch a display name. Returns
     * [entity_id => name]; entities the org module doesn't know about
     * (uninstalled, missing) just don't appear.
     */
    #[Computed]
    public function entityNames(): array
    {
        $ids = $this->rules->pluck('entity_ids')->flatten()->filter()->unique()->map(fn ($v) => (int) $v)->all();
        if (empty($ids)) {
            return [];
        }

        if (!\Illuminate\Support\Facades\Schema::hasTable('organization_entities')) {
            return [];
        }

        return DB::table('organization_entities')
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();
    }

    public function render()
    {
        return view('inbox::livewire.rules.index')
            ->layout('platform::layouts.app');
    }
}
