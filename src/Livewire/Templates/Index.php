<?php

namespace Platform\Inbox\Livewire\Templates;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Inbox\Models\InboxEnrichmentTemplate;

class Index extends Component
{
    public string $search = '';
    public string $channelFilter = '';

    public function toggleActive(int $id): void
    {
        $tpl = InboxEnrichmentTemplate::find($id);
        if (!$tpl) {
            return;
        }
        $this->guardEditable($tpl);
        $tpl->update(['is_active' => !$tpl->is_active]);
        unset($this->templates);
    }

    #[Computed]
    public function templates()
    {
        $teamId = auth()->user()->currentTeam->id;
        $query = InboxEnrichmentTemplate::query()
            ->where(function ($q) use ($teamId) {
                $q->whereNull('team_id')->orWhere('team_id', $teamId);
            })
            ->orderBy('name');

        if ($this->channelFilter !== '') {
            $query->forChannel($this->channelFilter);
        }
        if ($this->search !== '') {
            $like = '%' . $this->search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('key', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }

        return $query->limit(200)->get();
    }

    /**
     * Templates with team_id = null are global (shipped by Inbox itself). They
     * may not be edited or toggled from a team-scoped UI — clone-to-team first.
     */
    protected function guardEditable(InboxEnrichmentTemplate $tpl): void
    {
        $teamId = auth()->user()->currentTeam->id;
        abort_unless($tpl->team_id === $teamId, 403);
    }

    public function render()
    {
        return view('inbox::livewire.templates.index')
            ->layout('platform::layouts.app');
    }
}
