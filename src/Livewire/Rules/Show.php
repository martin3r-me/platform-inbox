<?php

namespace Platform\Inbox\Livewire\Rules;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Inbox\Models\InboxLinkRule;
use Platform\Inbox\Services\InboxEntityLinkService;
use Platform\Inbox\Services\InboxRuleEngine;

class Show extends Component
{
    public ?InboxLinkRule $rule = null;

    public array $form = [
        'name' => '',
        'priority' => 100,
        'is_active' => true,
        'channel' => '',
        'sender_kind' => '',
        'sender_identifier' => '',
        'sender_pattern' => '',
        'subject_pattern' => '',
        'body_pattern' => '',
        'entity_ids' => [],
        'also_mark_as' => '',
    ];

    public string $entitySearch = '';
    public ?string $flash = null;
    public bool $flashOk = false;

    public function mount(?InboxLinkRule $rule = null): void
    {
        if ($rule && $rule->exists) {
            abort_unless($rule->user_id === auth()->id(), 403);
            $this->rule = $rule;
            $this->form = [
                'name' => $rule->name,
                'priority' => $rule->priority,
                'is_active' => $rule->is_active,
                'channel' => $rule->channel ?? '',
                'sender_kind' => $rule->sender_kind ?? '',
                'sender_identifier' => $rule->sender_identifier ?? '',
                'sender_pattern' => $rule->sender_pattern ?? '',
                'subject_pattern' => $rule->subject_pattern ?? '',
                'body_pattern' => $rule->body_pattern ?? '',
                'entity_ids' => array_map(fn ($v) => (int) $v, (array) $rule->entity_ids),
                'also_mark_as' => $rule->also_mark_as ?? '',
            ];
        }
    }

    #[Computed]
    public function entityNames(): array
    {
        if (!Schema::hasTable('organization_entities') || empty($this->form['entity_ids'])) {
            return [];
        }
        return DB::table('organization_entities')
            ->whereIn('id', $this->form['entity_ids'])
            ->pluck('name', 'id')
            ->all();
    }

    #[Computed]
    public function entitySearchResults(): array
    {
        if (trim($this->entitySearch) === '') {
            return [];
        }
        $service = app(InboxEntityLinkService::class);
        $teamId = auth()->user()->currentTeam->id;
        $results = $service->search($this->entitySearch, $teamId, 12);
        return array_values(array_filter($results, fn ($r) => !in_array($r['id'], $this->form['entity_ids'], true)));
    }

    /**
     * Build a transient (unsaved) rule from the current form to drive the dry-run.
     */
    protected function transientRuleFromForm(): InboxLinkRule
    {
        $rule = new InboxLinkRule();
        $rule->user_id = auth()->id();
        $rule->team_id = auth()->user()->currentTeam->id;
        $rule->name = $this->form['name'] ?: 'Neue Regel';
        $rule->priority = (int) $this->form['priority'];
        $rule->is_active = (bool) $this->form['is_active'];
        $rule->channel = $this->form['channel'] ?: null;
        $rule->sender_kind = $this->form['sender_kind'] ?: null;
        $rule->sender_identifier = $this->form['sender_identifier'] ?: null;
        $rule->sender_pattern = $this->form['sender_pattern'] ?: null;
        $rule->subject_pattern = $this->form['subject_pattern'] ?: null;
        $rule->body_pattern = $this->form['body_pattern'] ?: null;
        $rule->entity_ids = $this->form['entity_ids'];
        $rule->also_mark_as = $this->form['also_mark_as'] ?: null;
        return $rule;
    }

    #[Computed]
    public function dryRun(): array
    {
        // Only run the preview if at least one match condition is set,
        // otherwise the result would simply be "everything in the last 30d".
        $hasCondition = collect([
            $this->form['channel'],
            $this->form['sender_kind'],
            $this->form['sender_identifier'],
            $this->form['sender_pattern'],
            $this->form['subject_pattern'],
            $this->form['body_pattern'],
        ])->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();

        if (!$hasCondition) {
            return ['enabled' => false, 'total' => 0, 'scanned' => 0, 'window_days' => 30, 'sample' => []];
        }

        $engine = app(InboxRuleEngine::class);
        $result = $engine->dryRun($this->transientRuleFromForm(), sinceDays: 30, sampleLimit: 8);
        return array_merge(['enabled' => true], $result);
    }

    public function addEntity(int $entityId): void
    {
        if (!in_array($entityId, $this->form['entity_ids'], true)) {
            $this->form['entity_ids'][] = $entityId;
        }
        $this->entitySearch = '';
        unset($this->entitySearchResults, $this->entityNames, $this->dryRun);
    }

    public function removeEntity(int $entityId): void
    {
        $this->form['entity_ids'] = array_values(array_filter(
            $this->form['entity_ids'],
            fn ($id) => (int) $id !== $entityId,
        ));
        unset($this->entityNames, $this->dryRun);
    }

    public function save()
    {
        $this->validate([
            'form.name' => 'required|string|max:255',
            'form.priority' => 'required|integer|min:0|max:1000',
            'form.entity_ids' => 'required|array|min:1',
            'form.channel' => 'nullable|in:mail,call,message,meeting',
            'form.sender_kind' => 'nullable|in:email,phone,teams',
        ]);

        $payload = [
            'team_id' => auth()->user()->currentTeam->id,
            'user_id' => auth()->id(),
            'name' => $this->form['name'],
            'priority' => (int) $this->form['priority'],
            'is_active' => (bool) $this->form['is_active'],
            'channel' => $this->form['channel'] ?: null,
            'sender_kind' => $this->form['sender_kind'] ?: null,
            'sender_identifier' => $this->form['sender_identifier'] ?: null,
            'sender_pattern' => $this->form['sender_pattern'] ?: null,
            'subject_pattern' => $this->form['subject_pattern'] ?: null,
            'body_pattern' => $this->form['body_pattern'] ?: null,
            'entity_ids' => array_map(fn ($v) => (int) $v, $this->form['entity_ids']),
            'also_mark_as' => $this->form['also_mark_as'] ?: null,
        ];

        if ($this->rule) {
            $this->rule->update($payload);
        } else {
            $this->rule = InboxLinkRule::create($payload);
        }

        $this->flash = 'Regel gespeichert.';
        $this->flashOk = true;

        return redirect()->route('inbox.rules.show', $this->rule);
    }

    public function render()
    {
        return view('inbox::livewire.rules.show')
            ->layout('platform::layouts.app');
    }
}
