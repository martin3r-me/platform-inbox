@php
    $channels = ['mail', 'call', 'message', 'meeting', 'recording'];
    $providers = [
        'openai:gpt-4o-mini',
        'openai:gpt-4o',
        'claude:claude-haiku-4-5',
        'claude:claude-sonnet-4-6',
        'claude:claude-opus-4-7',
    ];
    $isGlobal = $template && $template->team_id === null;
    $isNew = $template === null;
@endphp

<x-ui-page x-data="{}">
    <x-slot name="navbar">
        <x-ui-page-navbar
            title="{{ $isNew ? 'Neues Template' : $template->name }}"
            icon="heroicon-o-sparkles" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Inbox', 'href' => route('inbox.index'), 'icon' => 'inbox'],
            ['label' => 'Templates', 'href' => route('inbox.templates.index')],
            ['label' => $isNew ? 'Neu' : $template->name],
        ]">
            @if($isGlobal)
                <x-ui-button variant="secondary" size="sm" wire:click="cloneToTeam">
                    @svg('heroicon-o-document-duplicate', 'w-4 h-4')
                    <span>Ins Team kopieren</span>
                </x-ui-button>
            @else
                <x-ui-button variant="primary" size="sm" wire:click="save">
                    @svg('heroicon-o-check', 'w-4 h-4')
                    <span>Speichern</span>
                </x-ui-button>
            @endif
        </x-ui-page-actionbar>
    </x-slot>

    <div class="flex-1 min-w-0 min-h-0 flex flex-col overflow-auto p-6 space-y-4 max-w-4xl">
        @if($flash)
            <div class="rounded-lg px-3 py-2 text-[12px] {{ $flashOk ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-amber-50 text-amber-700 border border-amber-200' }}">
                {{ $flash }}
            </div>
        @endif

        @if($isGlobal)
            <div class="rounded-lg p-3 bg-amber-50 border border-amber-200 text-[12px] text-amber-800">
                @svg('heroicon-o-information-circle', 'w-4 h-4 inline-block mr-1')
                Globales Template — Read-Only. Zum Anpassen "Ins Team kopieren".
            </div>
        @endif

        {{-- Basics --}}
        <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-5 space-y-3">
            <h2 class="text-sm font-semibold text-[var(--ui-secondary)] m-0">Basics</h2>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)] block mb-1">Key</label>
                    <input wire:model="form.key" {{ $isGlobal ? 'disabled' : '' }}
                           class="w-full text-[12px] font-mono border border-[var(--ui-border)]/60 rounded px-2 py-1 disabled:bg-[var(--ui-muted-5)]" />
                </div>
                <div>
                    <label class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)] block mb-1">Name</label>
                    <input wire:model="form.name" {{ $isGlobal ? 'disabled' : '' }}
                           class="w-full text-[12px] border border-[var(--ui-border)]/60 rounded px-2 py-1 disabled:bg-[var(--ui-muted-5)]" />
                </div>
            </div>
            <div>
                <label class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)] block mb-1">Beschreibung</label>
                <input wire:model="form.description" {{ $isGlobal ? 'disabled' : '' }}
                       class="w-full text-[12px] border border-[var(--ui-border)]/60 rounded px-2 py-1 disabled:bg-[var(--ui-muted-5)]" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)] block mb-1">Provider</label>
                    <select wire:model="form.preferred_provider" {{ $isGlobal ? 'disabled' : '' }}
                            class="w-full text-[12px] font-mono border border-[var(--ui-border)]/60 rounded px-2 py-1 disabled:bg-[var(--ui-muted-5)]">
                        @foreach($providers as $p)
                            <option value="{{ $p }}">{{ $p }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end gap-4 pb-1 text-[12px]">
                    <label class="flex items-center gap-1">
                        <input type="checkbox" wire:model="form.is_active" {{ $isGlobal ? 'disabled' : '' }} />
                        <span>Aktiv</span>
                    </label>
                    <label class="flex items-center gap-1">
                        <input type="checkbox" wire:model="form.is_default_for_channel" {{ $isGlobal ? 'disabled' : '' }} />
                        <span>Default für Kanal</span>
                    </label>
                </div>
            </div>
        </div>

        {{-- Kanäle --}}
        <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-5 space-y-2">
            <h2 class="text-sm font-semibold text-[var(--ui-secondary)] m-0">Anwendbare Kanäle</h2>
            <div class="flex flex-wrap gap-2">
                @foreach($channels as $ch)
                    @php $on = in_array($ch, (array) $form['applicable_channels'], true); @endphp
                    <button wire:click="toggleChannel('{{ $ch }}')" type="button" {{ $isGlobal ? 'disabled' : '' }}
                            class="text-[11px] px-2 py-1 rounded border {{ $on ? 'bg-[var(--ui-primary)] text-white border-[var(--ui-primary)]' : 'bg-white text-[var(--ui-secondary)] border-[var(--ui-border)]/60' }} disabled:opacity-50">
                        {{ ucfirst($ch) }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Prompts --}}
        <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-5 space-y-3">
            <h2 class="text-sm font-semibold text-[var(--ui-secondary)] m-0">Prompts</h2>
            <div>
                <label class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)] block mb-1">
                    System-Prompt
                    <span class="ml-2 text-[var(--ui-muted)] font-normal normal-case">— optional</span>
                </label>
                <textarea wire:model="form.system_prompt" {{ $isGlobal ? 'disabled' : '' }} rows="3"
                          class="w-full text-[12px] font-mono border border-[var(--ui-border)]/60 rounded px-2 py-1 disabled:bg-[var(--ui-muted-5)]"></textarea>
            </div>
            <div>
                <label class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)] block mb-1">
                    Prompt-Template
                    <span class="ml-2 text-[var(--ui-muted)] font-normal normal-case">Placeholder: <code>{body}</code> <code>{subject}</code> <code>{sender}</code> <code>{channel}</code> <code>{language}</code> <code>{participants_list}</code></span>
                </label>
                <textarea wire:model="form.prompt_template" {{ $isGlobal ? 'disabled' : '' }} rows="12"
                          class="w-full text-[12px] font-mono border border-[var(--ui-border)]/60 rounded px-2 py-1 disabled:bg-[var(--ui-muted-5)]"></textarea>
            </div>
        </div>

        {{-- Output Schema --}}
        <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-5 space-y-2">
            <h2 class="text-sm font-semibold text-[var(--ui-secondary)] m-0">Output-Schema</h2>
            <p class="text-[11px] text-[var(--ui-muted)] m-0">JSON-Schema, das die Felder beschreibt, die der Provider produzieren soll. Die Show-View nutzt es, um Ergebnisse zu rendern.</p>
            <textarea wire:model="form.output_schema" {{ $isGlobal ? 'disabled' : '' }} rows="14"
                      class="w-full text-[11px] font-mono border border-[var(--ui-border)]/60 rounded px-2 py-1 disabled:bg-[var(--ui-muted-5)]"></textarea>
        </div>

        @if(!$isNew && !$isGlobal)
            <div class="text-[11px] text-[var(--ui-muted)] italic">
                Aktuelle Version: <strong>v{{ $template->version }}</strong>.
                Bei Änderungen an Prompt, System-Prompt oder Schema wird die Version beim Speichern automatisch hochgezählt — vorhandene Anreicherungsläufe behalten ihre Version für die Forensik.
            </div>
        @endif
    </div>
</x-ui-page>
