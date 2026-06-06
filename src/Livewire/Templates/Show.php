<?php

namespace Platform\Inbox\Livewire\Templates;

use Livewire\Component;
use Platform\Inbox\Models\InboxEnrichmentTemplate;

class Show extends Component
{
    public ?InboxEnrichmentTemplate $template = null;

    public array $form = [
        'key' => '',
        'name' => '',
        'description' => '',
        'applicable_channels' => [],
        'prompt_template' => '',
        'system_prompt' => '',
        'preferred_provider' => 'openai:gpt-4o-mini',
        'output_schema' => '',          // JSON as string for editing
        'is_active' => true,
        'is_default_for_channel' => false,
    ];

    public ?string $flash = null;
    public bool $flashOk = false;

    /** Original prompt+system+schema — used to detect changes for version-bump. */
    protected array $original = [];

    public function mount(?InboxEnrichmentTemplate $template = null): void
    {
        $teamId = auth()->user()->currentTeam->id;

        if ($template && $template->exists) {
            // Allow viewing global templates; editing is gated in save().
            $this->template = $template;
            $this->form = [
                'key' => $template->key,
                'name' => $template->name,
                'description' => $template->description ?? '',
                'applicable_channels' => (array) $template->applicable_channels,
                'prompt_template' => $template->prompt_template,
                'system_prompt' => $template->system_prompt ?? '',
                'preferred_provider' => $template->preferred_provider,
                'output_schema' => json_encode($template->output_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'is_active' => (bool) $template->is_active,
                'is_default_for_channel' => (bool) $template->is_default_for_channel,
            ];
            $this->original = [
                'prompt_template' => $template->prompt_template,
                'system_prompt' => $template->system_prompt ?? '',
                'output_schema' => $this->form['output_schema'],
            ];
        }
    }

    public function toggleChannel(string $channel): void
    {
        $set = array_flip($this->form['applicable_channels']);
        if (isset($set[$channel])) {
            unset($set[$channel]);
        } else {
            $set[$channel] = true;
        }
        $this->form['applicable_channels'] = array_keys($set);
    }

    public function save()
    {
        $teamId = auth()->user()->currentTeam->id;

        // Global templates (team_id null) cannot be edited from a team UI.
        if ($this->template && $this->template->team_id === null) {
            $this->flash = 'Globale Templates können hier nicht bearbeitet werden — bitte Kopie anlegen.';
            $this->flashOk = false;
            return;
        }
        if ($this->template && $this->template->team_id !== $teamId) {
            abort(403);
        }

        $this->validate([
            'form.key' => 'required|string|max:255',
            'form.name' => 'required|string|max:255',
            'form.applicable_channels' => 'required|array|min:1',
            'form.prompt_template' => 'required|string|min:1',
            'form.preferred_provider' => 'required|string|max:100',
            'form.output_schema' => 'required|string|min:2',
        ]);

        $schema = json_decode($this->form['output_schema'], true);
        if (!is_array($schema)) {
            $this->flash = 'output_schema ist kein gültiges JSON.';
            $this->flashOk = false;
            return;
        }

        $payload = [
            'team_id' => $teamId,
            'key' => $this->form['key'],
            'name' => $this->form['name'],
            'description' => $this->form['description'] ?: null,
            'applicable_channels' => array_values($this->form['applicable_channels']),
            'prompt_template' => $this->form['prompt_template'],
            'system_prompt' => $this->form['system_prompt'] ?: null,
            'preferred_provider' => $this->form['preferred_provider'],
            'output_schema' => $schema,
            'is_active' => (bool) $this->form['is_active'],
            'is_default_for_channel' => (bool) $this->form['is_default_for_channel'],
        ];

        if ($this->template) {
            // Auto-bump version when prompt/system/schema changed — old runs
            // keep their original version number for forensic comparison.
            $bumped = $this->original['prompt_template'] !== $this->form['prompt_template']
                || $this->original['system_prompt'] !== $this->form['system_prompt']
                || $this->original['output_schema'] !== $this->form['output_schema'];
            if ($bumped) {
                $payload['version'] = $this->template->version + 1;
            }
            $this->template->update($payload);
            $this->flash = $bumped
                ? 'Template gespeichert (Version → v' . $payload['version'] . ').'
                : 'Template gespeichert.';
            $this->flashOk = true;
            return redirect()->route('inbox.templates.show', $this->template);
        }

        $payload['version'] = 1;
        $this->template = InboxEnrichmentTemplate::create($payload);
        $this->flash = 'Template angelegt.';
        $this->flashOk = true;
        return redirect()->route('inbox.templates.show', $this->template);
    }

    /**
     * Clone a global (team_id null) template into the current team so the
     * user can edit it. The clone keeps the same key + version=1.
     */
    public function cloneToTeam()
    {
        if (!$this->template || $this->template->team_id !== null) {
            return;
        }
        $teamId = auth()->user()->currentTeam->id;
        $clone = $this->template->replicate(['uuid']);
        $clone->team_id = $teamId;
        $clone->version = 1;
        $clone->save();
        return redirect()->route('inbox.templates.show', $clone);
    }

    public function render()
    {
        return view('inbox::livewire.templates.show')
            ->layout('platform::layouts.app');
    }
}
