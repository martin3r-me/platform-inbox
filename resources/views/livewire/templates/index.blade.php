@php
    $templates = $this->templates;
    $teamId = auth()->user()->currentTeam->id;
    $channels = ['mail', 'call', 'message', 'meeting', 'recording'];
@endphp

<x-ui-page x-data="{}">
    <x-slot name="navbar">
        <x-ui-page-navbar title="Templates" icon="heroicon-o-sparkles" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Inbox', 'href' => route('inbox.index'), 'icon' => 'inbox'],
            ['label' => 'Templates'],
        ]">
            <x-ui-button variant="primary" size="sm" href="{{ route('inbox.templates.create') }}">
                @svg('heroicon-o-plus', 'w-4 h-4')
                <span>Neues Template</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Übersicht" icon="heroicon-o-information-circle" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-5 bg-[var(--ui-muted-5)]">
                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Über</h3>
                    <p class="text-[11px] text-[var(--ui-secondary)] leading-relaxed m-0">
                        Templates steuern, wie ein Inbox-Item angereichert wird: Prompt, System-Prompt, Output-Schema und bevorzugter Provider. Globale Templates sind Read-Only — Kopie ins Team anlegen zum Anpassen.
                    </p>
                </section>
                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Suche</h3>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Name oder Key..."
                        class="w-full text-[11px] border border-[var(--ui-border)]/60 rounded px-2 py-1"
                    />
                </section>
                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Kanal-Filter</h3>
                    <select wire:model.live="channelFilter" class="w-full text-[11px] border border-[var(--ui-border)]/60 rounded px-2 py-1">
                        <option value="">— alle —</option>
                        @foreach($channels as $c)
                            <option value="{{ $c }}">{{ ucfirst($c) }}</option>
                        @endforeach
                    </select>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 min-w-0 min-h-0 flex flex-col overflow-auto p-6">
        @if($templates->isEmpty())
            <div class="py-12 text-center rounded-lg border border-dashed border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-sparkles', 'w-8 h-8 text-[var(--ui-muted)] mx-auto mb-3')
                <p class="text-sm text-[var(--ui-muted)] mb-3">Keine Templates gefunden.</p>
                <x-ui-button variant="primary" size="sm" href="{{ route('inbox.templates.create') }}">
                    @svg('heroicon-o-plus', 'w-4 h-4')
                    <span>Erstes Template anlegen</span>
                </x-ui-button>
            </div>
        @else
            <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg overflow-hidden">
                <table class="w-full text-[12px]">
                    <thead class="bg-[var(--ui-muted-5)] text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">
                        <tr>
                            <th class="text-left px-3 py-2">Name / Key</th>
                            <th class="text-left px-3 py-2">Kanäle</th>
                            <th class="text-left px-3 py-2">Provider</th>
                            <th class="text-left px-3 py-2">Version</th>
                            <th class="text-left px-3 py-2">Scope</th>
                            <th class="text-left px-3 py-2">Aktiv</th>
                            <th class="text-right px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/40">
                        @foreach($templates as $tpl)
                            @php $isGlobal = $tpl->team_id === null; @endphp
                            <tr class="hover:bg-[var(--ui-muted-5)]/50">
                                <td class="px-3 py-2">
                                    <a href="{{ route('inbox.templates.show', $tpl) }}" class="text-[var(--ui-secondary)] hover:underline font-medium">{{ $tpl->name }}</a>
                                    <div class="text-[10px] text-[var(--ui-muted)] font-mono">{{ $tpl->key }}</div>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach((array) $tpl->applicable_channels as $ch)
                                            <span class="text-[10px] px-1.5 py-0.5 bg-[var(--ui-muted-5)] rounded">{{ $ch }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-3 py-2 font-mono text-[10px] text-[var(--ui-muted)]">{{ $tpl->preferred_provider }}</td>
                                <td class="px-3 py-2 tabular-nums">v{{ $tpl->version }}</td>
                                <td class="px-3 py-2">
                                    @if($isGlobal)
                                        <span class="text-[10px] px-1.5 py-0.5 bg-amber-50 text-amber-700 border border-amber-200 rounded">global</span>
                                    @else
                                        <span class="text-[10px] px-1.5 py-0.5 bg-blue-50 text-blue-700 border border-blue-200 rounded">team</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @if($isGlobal)
                                        <span class="text-[10px] text-[var(--ui-muted)]">{{ $tpl->is_active ? 'an' : 'aus' }}</span>
                                    @else
                                        <button wire:click="toggleActive({{ $tpl->id }})" class="text-[10px] {{ $tpl->is_active ? 'text-green-700' : 'text-[var(--ui-muted)]' }}">
                                            {{ $tpl->is_active ? 'an' : 'aus' }}
                                        </button>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <a href="{{ route('inbox.templates.show', $tpl) }}" class="text-[10px] text-blue-600 hover:underline">Öffnen ↗</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-ui-page>
