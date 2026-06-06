@php
    $buckets = $this->buckets;
    $totalSnoozed = $this->items->count();
@endphp

<x-ui-page x-data="{}">
    <x-slot name="navbar">
        <x-ui-page-navbar title="Snoozed" icon="heroicon-o-clock" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Inbox', 'href' => route('inbox.index'), 'icon' => 'inbox'],
            ['label' => 'Snoozed'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Übersicht" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-5 bg-[var(--ui-muted-5)]">
                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Über</h3>
                    <p class="text-[11px] text-[var(--ui-secondary)] leading-relaxed m-0">
                        Beiseite gelegte Einträge — kommen automatisch zurück, sobald die Snooze-Zeit abgelaufen ist. Kannst du jederzeit verschieben oder wieder aufwecken.
                    </p>
                </section>
                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Verteilung</h3>
                    <ul class="space-y-1 list-none p-0 m-0">
                        @foreach($buckets as $b)
                            <li class="flex items-center justify-between text-[11px]">
                                <span class="flex items-center gap-2 text-[var(--ui-secondary)]">
                                    @svg($b['icon'], 'w-3.5 h-3.5 text-[var(--ui-muted)]')
                                    {{ $b['label'] }}
                                </span>
                                <span class="tabular-nums text-[var(--ui-muted)]">{{ $b['items']->count() }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Stand</h3>
                    <p class="text-[11px] text-[var(--ui-secondary)] m-0">
                        <strong>{{ $totalSnoozed }}</strong> insgesamt auf Snooze
                    </p>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Reschedule" icon="heroicon-o-arrow-path" width="w-72" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3">
                <section>
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Hinweis</h3>
                    <p class="text-[11px] text-[var(--ui-muted)] leading-relaxed m-0">
                        Direkt am Item: Schnellaktionen zum Verschieben (1h / 4h / 1d / 1w) oder Aufwecken.
                    </p>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 min-w-0 min-h-0 flex flex-col overflow-auto p-6 space-y-6">
        @if($totalSnoozed === 0)
            <div class="py-12 text-center rounded-lg border border-dashed border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-clock', 'w-8 h-8 text-[var(--ui-muted)] mx-auto mb-3')
                <p class="text-sm text-[var(--ui-muted)]">Nichts auf Snooze.</p>
            </div>
        @else
            @foreach($buckets as $bucket)
                @if($bucket['items']->isNotEmpty())
                    <section>
                        <div class="flex items-center gap-2 mb-3 px-1">
                            @svg($bucket['icon'], 'w-4 h-4 text-[var(--ui-muted)]')
                            <h3 class="text-xs font-bold text-[var(--ui-secondary)] uppercase tracking-wider">{{ $bucket['label'] }}</h3>
                            <span class="text-[10px] text-[var(--ui-muted)]">({{ $bucket['items']->count() }})</span>
                        </div>
                        <div class="space-y-2">
                            @foreach($bucket['items'] as $item)
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
                                            </div>
                                        </a>
                                    </div>
                                    <div class="text-[11px] text-[var(--ui-muted)] tabular-nums whitespace-nowrap text-right">
                                        <div>wieder {{ $item->snoozed_until?->format('d.m. H:i') }}</div>
                                        <div class="text-[10px] opacity-70">{{ $item->snoozed_until?->diffForHumans() }}</div>
                                    </div>
                                    <div class="flex items-center gap-1 flex-shrink-0" x-data="{ open: false }" @click.away="open = false">
                                        <button wire:click="wakeUp({{ $item->id }})" title="Jetzt wieder zeigen"
                                                class="p-1.5 rounded border border-[var(--ui-border)]/60 hover:bg-blue-50">
                                            @svg('heroicon-o-arrow-uturn-left', 'w-3.5 h-3.5 text-[var(--ui-muted)]')
                                        </button>
                                        <div class="relative">
                                            <button @click="open = !open" title="Verschieben"
                                                    class="p-1.5 rounded border border-[var(--ui-border)]/60 hover:bg-yellow-50">
                                                @svg('heroicon-o-clock', 'w-3.5 h-3.5 text-[var(--ui-muted)]')
                                            </button>
                                            <div x-show="open" x-cloak x-transition.origin.top.right
                                                 class="absolute right-0 top-full mt-1 z-30 min-w-40 bg-white border border-[var(--ui-border)]/60 rounded-md shadow-lg py-1 text-[12px]">
                                                <button wire:click="reschedule({{ $item->id }}, 1)" @click="open = false" class="w-full text-left px-3 py-1.5 hover:bg-[var(--ui-muted-5)]">+ 1 Stunde</button>
                                                <button wire:click="reschedule({{ $item->id }}, 4)" @click="open = false" class="w-full text-left px-3 py-1.5 hover:bg-[var(--ui-muted-5)]">+ 4 Stunden</button>
                                                <button wire:click="reschedule({{ $item->id }}, 24)" @click="open = false" class="w-full text-left px-3 py-1.5 hover:bg-[var(--ui-muted-5)]">+ 1 Tag</button>
                                                <button wire:click="reschedule({{ $item->id }}, 168)" @click="open = false" class="w-full text-left px-3 py-1.5 hover:bg-[var(--ui-muted-5)]">+ 1 Woche</button>
                                            </div>
                                        </div>
                                        <button wire:click="markDone({{ $item->id }})" title="Direkt erledigen"
                                                class="p-1.5 rounded border border-[var(--ui-border)]/60 hover:bg-green-50">
                                            @svg('heroicon-o-check', 'w-3.5 h-3.5 text-[var(--ui-muted)]')
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            @endforeach
        @endif
    </div>
</x-ui-page>
