@php
    $items = $this->items;
    $openCount = $items->count();
    $byChannel = $items->groupBy(fn ($i) => $i->channel?->value)->map->count();
@endphp

<x-ui-page x-data="{}">
    <x-slot name="navbar">
        <x-ui-page-navbar title="Inbox" icon="heroicon-o-inbox" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Inbox'],
        ]">
            <x-ui-button variant="ghost" size="sm" href="{{ route('inbox.snoozed') }}">
                @svg('heroicon-o-clock', 'w-4 h-4')
                <span>Snoozed</span>
            </x-ui-button>
            <x-ui-button variant="ghost" size="sm" href="{{ route('inbox.subscriptions') }}">
                @svg('heroicon-o-bell', 'w-4 h-4')
                <span>Abonnements</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Übersicht" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-5 bg-[var(--ui-muted-5)]">

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Über</h3>
                    <p class="text-[11px] text-[var(--ui-secondary)] leading-relaxed m-0">
                        Mails, Anrufe, Nachrichten und Meeting-Einladungen aus deinen User-Connectors — gebündelt zur Triage. Versand und Antworten landen automatisch in den Original-Diensten.
                    </p>
                </section>

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Suche</h3>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Betreff, Absender, Text..."
                        class="w-full text-[11px] border border-[var(--ui-border)]/60 rounded px-2 py-1"
                    />
                </section>

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Kanäle</h3>
                    <div class="space-y-1">
                        @foreach([
                            '' => ['label' => 'Alle Kanäle', 'icon' => 'heroicon-o-rectangle-stack'],
                            'mail' => ['label' => 'E-Mail', 'icon' => 'heroicon-o-envelope'],
                            'call' => ['label' => 'Anrufe', 'icon' => 'heroicon-o-phone'],
                            'message' => ['label' => 'Nachrichten', 'icon' => 'heroicon-o-chat-bubble-left'],
                            'meeting' => ['label' => 'Meetings', 'icon' => 'heroicon-o-calendar-days'],
                        ] as $val => $opt)
                            <button
                                wire:click="$set('channel', '{{ $val }}')"
                                class="w-full flex items-center justify-between gap-2 px-2 py-1 text-[11px] rounded transition-colors {{ $channel === $val ? 'bg-[var(--ui-muted-5)] text-[var(--ui-secondary)] font-medium' : 'text-[var(--ui-muted)] hover:bg-[var(--ui-muted-5)]' }}"
                            >
                                <span class="flex items-center gap-2">
                                    @svg($opt['icon'], 'w-3.5 h-3.5')
                                    {{ $opt['label'] }}
                                </span>
                                @if($val !== '')
                                    <span class="tabular-nums">{{ $byChannel[$val] ?? 0 }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </section>

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Stand</h3>
                    <p class="text-[11px] text-[var(--ui-secondary)] m-0">
                        <strong>{{ $openCount }}</strong> offene Einträge {{ $channel ? '(' . $channel . ')' : '' }}
                    </p>
                </section>

            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="p-6">
        @if($items->isEmpty())
            <div class="py-12 text-center rounded-lg border border-dashed border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-inbox', 'w-8 h-8 text-[var(--ui-muted)] mx-auto mb-3')
                <p class="text-sm text-[var(--ui-muted)]">Keine offenen Einträge.</p>
            </div>
        @else
            <div class="space-y-2">
                @foreach($items as $item)
                    <div class="flex items-center gap-3 p-3 bg-white border border-[var(--ui-border)]/40 rounded-lg hover:shadow-sm transition-shadow">
                        <div class="w-20 flex-shrink-0">
                            <span class="inline-flex items-center gap-1 text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">
                                @svg($item->channel?->icon() ?? 'heroicon-o-inbox', 'w-3 h-3')
                                {{ $item->channel?->label() }}
                            </span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <a href="{{ route('inbox.items.show', $item) }}" class="block">
                                <div class="text-sm font-medium text-[var(--ui-secondary)] truncate">
                                    {{ $item->subject ?: $item->sender_label ?: $item->sender_identifier ?: '(ohne Betreff)' }}
                                </div>
                                <div class="text-xs text-[var(--ui-muted)] truncate">
                                    {{ $item->sender_label ?: $item->sender_identifier }}
                                    @if($item->preview)
                                        <span class="mx-1">·</span>{{ Str::limit($item->preview, 140) }}
                                    @endif
                                </div>
                            </a>
                        </div>
                        <div class="text-[10px] text-[var(--ui-muted)] tabular-nums whitespace-nowrap">
                            {{ $item->received_at?->diffForHumans() }}
                        </div>
                        <div class="flex gap-1 flex-shrink-0">
                            <button wire:click="markDone({{ $item->id }})" title="Erledigt" class="p-1.5 rounded border border-[var(--ui-border)]/60 hover:bg-green-50">
                                @svg('heroicon-o-check', 'w-3.5 h-3.5 text-[var(--ui-muted)]')
                            </button>
                            <button wire:click="snooze({{ $item->id }}, 4)" title="4h snoozen" class="p-1.5 rounded border border-[var(--ui-border)]/60 hover:bg-yellow-50">
                                @svg('heroicon-o-clock', 'w-3.5 h-3.5 text-[var(--ui-muted)]')
                            </button>
                            <button wire:click="ignore({{ $item->id }})" title="Ignorieren" class="p-1.5 rounded border border-[var(--ui-border)]/60 hover:bg-[var(--ui-muted-5)]">
                                @svg('heroicon-o-x-mark', 'w-3.5 h-3.5 text-[var(--ui-muted)]')
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-ui-page>
