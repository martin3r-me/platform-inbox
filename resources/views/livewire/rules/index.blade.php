@php
    $rules = $this->rules;
    $names = $this->entityNames;
    $activeCount = $rules->where('is_active', true)->count();
@endphp

<x-ui-page x-data="{}">
    <x-slot name="navbar">
        <x-ui-page-navbar title="Regeln" icon="heroicon-o-funnel" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Inbox', 'href' => route('inbox.index'), 'icon' => 'inbox'],
            ['label' => 'Regeln'],
        ]">
            <x-ui-button variant="primary" size="sm" href="{{ route('inbox.rules.create') }}">
                @svg('heroicon-o-plus', 'w-4 h-4')
                <span>Neue Regel</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Übersicht" icon="heroicon-o-information-circle" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-5 bg-[var(--ui-muted-5)]">
                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Über</h3>
                    <p class="text-[11px] text-[var(--ui-secondary)] leading-relaxed m-0">
                        Regeln verknüpfen eingehende Items automatisch mit Organization-Entities. Mehrere Regeln können gleichzeitig greifen — eine Mail kann legitim an mehreren Entities hängen.
                    </p>
                </section>
                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Suche</h3>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Name oder Pattern..."
                        class="w-full text-[11px] border border-[var(--ui-border)]/60 rounded px-2 py-1"
                    />
                </section>
                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Stand</h3>
                    <p class="text-[11px] text-[var(--ui-secondary)] m-0">
                        <strong>{{ $activeCount }}</strong> aktiv · {{ $rules->count() - $activeCount }} pausiert
                    </p>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 min-w-0 min-h-0 flex flex-col overflow-auto p-6">

        @if($rules->isEmpty())
            <div class="py-12 text-center rounded-lg border border-dashed border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-funnel', 'w-8 h-8 text-[var(--ui-muted)] mx-auto mb-3')
                <p class="text-sm text-[var(--ui-muted)] mb-3">Noch keine Regeln angelegt.</p>
                <x-ui-button variant="primary" size="sm" href="{{ route('inbox.rules.create') }}">
                    @svg('heroicon-o-plus', 'w-4 h-4')
                    <span>Erste Regel anlegen</span>
                </x-ui-button>
            </div>
        @else
            <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg divide-y divide-[var(--ui-border)]/40">
                @foreach($rules as $rule)
                    <div class="flex items-center gap-3 px-4 py-3 hover:bg-[var(--ui-muted-5)]/50 transition-colors {{ $rule->is_active ? '' : 'opacity-60' }}">
                        <button wire:click="toggleActive({{ $rule->id }})" title="{{ $rule->is_active ? 'Pausieren' : 'Aktivieren' }}"
                                class="flex-shrink-0 w-8 h-5 rounded-full transition-colors {{ $rule->is_active ? 'bg-green-500' : 'bg-gray-300' }} relative">
                            <span class="absolute top-0.5 {{ $rule->is_active ? 'right-0.5' : 'left-0.5' }} w-4 h-4 bg-white rounded-full shadow"></span>
                        </button>
                        <div class="flex-1 min-w-0">
                            <a href="{{ route('inbox.rules.show', $rule) }}" class="block">
                                <div class="text-sm font-medium text-[var(--ui-secondary)] truncate">{{ $rule->name }}</div>
                                <div class="text-[11px] text-[var(--ui-muted)] truncate flex flex-wrap gap-2 mt-0.5">
                                    @if($rule->channel)
                                        <span>Kanal: <strong>{{ $rule->channel }}</strong></span>
                                    @endif
                                    @if($rule->sender_identifier)
                                        <span>Absender: <strong>{{ Str::limit($rule->sender_identifier, 30) }}</strong></span>
                                    @elseif($rule->sender_pattern)
                                        <span>Absender-Pattern: <strong>{{ $rule->sender_pattern }}</strong></span>
                                    @endif
                                    @if($rule->subject_pattern)
                                        <span>Subject: <strong>{{ Str::limit($rule->subject_pattern, 24) }}</strong></span>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @foreach($rule->entity_ids ?? [] as $eid)
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[10px] bg-blue-50 text-blue-700 border border-blue-200 rounded">
                                            @svg('heroicon-o-cube', 'w-2.5 h-2.5')
                                            {{ $names[(int)$eid] ?? '#' . $eid }}
                                        </span>
                                    @endforeach
                                </div>
                            </a>
                        </div>
                        <div class="text-right text-[11px] text-[var(--ui-muted)] tabular-nums whitespace-nowrap flex-shrink-0">
                            <div><strong class="text-[var(--ui-secondary)]">{{ $rule->matched_count }}×</strong> getroffen</div>
                            @if($rule->last_matched_at)
                                <div class="text-[10px]">zuletzt {{ $rule->last_matched_at->diffForHumans() }}</div>
                            @endif
                        </div>
                        <div class="flex-shrink-0 flex items-center gap-1">
                            <a href="{{ route('inbox.rules.show', $rule) }}" class="p-1.5 rounded border border-[var(--ui-border)]/60 hover:bg-[var(--ui-muted-5)]">
                                @svg('heroicon-o-pencil', 'w-3.5 h-3.5 text-[var(--ui-muted)]')
                            </a>
                            <button wire:click="deleteRule({{ $rule->id }})"
                                    wire:confirm="Diese Regel wirklich löschen?"
                                    class="p-1.5 rounded border border-[var(--ui-border)]/60 hover:bg-red-50">
                                @svg('heroicon-o-trash', 'w-3.5 h-3.5 text-[var(--ui-muted)]')
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-ui-page>
