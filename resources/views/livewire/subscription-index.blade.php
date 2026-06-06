@php
    $subs = $this->subscriptions;
    $volumes = $this->volumesBySender;

    $kindMeta = [
        'email' => ['label' => 'E-Mail', 'icon' => 'heroicon-o-envelope'],
        'phone' => ['label' => 'Telefon', 'icon' => 'heroicon-o-phone'],
        'teams' => ['label' => 'Teams', 'icon' => 'heroicon-o-chat-bubble-left'],
    ];

    $groups = collect($kindMeta)
        ->map(fn ($meta, $kind) => [
            'kind' => $kind,
            'label' => $meta['label'],
            'icon' => $meta['icon'],
            'subs' => $subs->where('sender_kind', $kind)->values(),
        ])
        ->filter(fn ($g) => $g['subs']->isNotEmpty())
        ->values();

    $counts = [
        'all' => $subs->count(),
        'subscribed' => $subs->where('status.value', 'subscribed')->count(),
        'muted' => $subs->where('status.value', 'muted')->count(),
        'unsubscribed' => $subs->where('status.value', 'unsubscribed')->count(),
        'vip' => $subs->where('is_vip', true)->count(),
    ];
@endphp

<x-ui-page x-data="{}">
    <x-slot name="navbar">
        <x-ui-page-navbar title="Abonnements" icon="heroicon-o-bell" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Inbox', 'href' => route('inbox.index'), 'icon' => 'inbox'],
            ['label' => 'Abonnements'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" icon="heroicon-o-funnel" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-5 bg-[var(--ui-muted-5)]">

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Über</h3>
                    <p class="text-[11px] text-[var(--ui-secondary)] leading-relaxed m-0">
                        Pro Absender (E-Mail, Telefon, Teams) bestimmst du, wie er in deinem Inbox auftaucht — oder eben nicht.
                    </p>
                </section>

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Suche</h3>
                    <input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Name oder Bezeichner..."
                        class="w-full text-[11px] border border-[var(--ui-border)]/60 rounded px-2 py-1"
                    />
                </section>

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Status</h3>
                    <div class="space-y-1">
                        @foreach([
                            '' => ['label' => 'Alle', 'icon' => 'heroicon-o-rectangle-stack', 'count' => $counts['all']],
                            'subscribed' => ['label' => 'Abonniert', 'icon' => 'heroicon-o-check', 'count' => $counts['subscribed']],
                            'muted' => ['label' => 'Stumm', 'icon' => 'heroicon-o-speaker-x-mark', 'count' => $counts['muted']],
                            'unsubscribed' => ['label' => 'Abbestellt', 'icon' => 'heroicon-o-no-symbol', 'count' => $counts['unsubscribed']],
                        ] as $val => $opt)
                            <button
                                wire:click="$set('filterStatus', '{{ $val }}')"
                                class="w-full flex items-center justify-between gap-2 px-2 py-1 text-[11px] rounded transition-colors {{ $filterStatus === $val ? 'bg-[var(--ui-muted-5)] text-[var(--ui-secondary)] font-medium' : 'text-[var(--ui-muted)] hover:bg-[var(--ui-muted-5)]' }}"
                            >
                                <span class="flex items-center gap-2">
                                    @svg($opt['icon'], 'w-3.5 h-3.5')
                                    {{ $opt['label'] }}
                                </span>
                                <span class="tabular-nums">{{ $opt['count'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Kanal</h3>
                    <div class="space-y-1">
                        @foreach([
                            '' => ['label' => 'Alle', 'icon' => 'heroicon-o-rectangle-stack'],
                            'email' => ['label' => 'E-Mail', 'icon' => 'heroicon-o-envelope'],
                            'phone' => ['label' => 'Telefon', 'icon' => 'heroicon-o-phone'],
                            'teams' => ['label' => 'Teams', 'icon' => 'heroicon-o-chat-bubble-left'],
                        ] as $val => $opt)
                            <button
                                wire:click="$set('filterKind', '{{ $val }}')"
                                class="w-full flex items-center justify-between gap-2 px-2 py-1 text-[11px] rounded transition-colors {{ $filterKind === $val ? 'bg-[var(--ui-muted-5)] text-[var(--ui-secondary)] font-medium' : 'text-[var(--ui-muted)] hover:bg-[var(--ui-muted-5)]' }}"
                            >
                                <span class="flex items-center gap-2">
                                    @svg($opt['icon'], 'w-3.5 h-3.5')
                                    {{ $opt['label'] }}
                                </span>
                            </button>
                        @endforeach
                    </div>
                </section>

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">VIP</h3>
                    <p class="text-[11px] text-[var(--ui-secondary)] m-0">
                        <span class="text-yellow-500">@svg('heroicon-s-star', 'w-3.5 h-3.5 inline')</span>
                        <strong>{{ $counts['vip'] }}</strong> Absender markiert
                    </p>
                </section>

            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Hinzufügen" icon="heroicon-o-plus-circle" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3">
                <p class="text-[11px] text-[var(--ui-muted)] m-0">
                    Absender manuell anlegen — z.B. bevor er dir das erste Mal schreibt.
                </p>
                <form wire:submit.prevent="addSubscription" class="space-y-2">
                    <div>
                        <label class="text-[10px] uppercase text-[var(--ui-muted)]">Kanal</label>
                        <select wire:model="newSubscription.sender_kind" class="w-full text-[12px] border border-[var(--ui-border)]/60 rounded px-2 py-1">
                            <option value="email">E-Mail</option>
                            <option value="phone">Telefon</option>
                            <option value="teams">Teams</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] uppercase text-[var(--ui-muted)]">Bezeichner</label>
                        <input type="text" wire:model="newSubscription.sender_identifier"
                               placeholder="z.B. newsletter@firma.de oder +49…"
                               class="w-full text-[12px] border border-[var(--ui-border)]/60 rounded px-2 py-1" />
                        @error('newSubscription.sender_identifier')
                            <p class="text-[10px] text-red-500 mt-0.5">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="text-[10px] uppercase text-[var(--ui-muted)]">Label (optional)</label>
                        <input type="text" wire:model="newSubscription.label"
                               placeholder="Anzeigename"
                               class="w-full text-[12px] border border-[var(--ui-border)]/60 rounded px-2 py-1" />
                    </div>
                    <div>
                        <label class="text-[10px] uppercase text-[var(--ui-muted)]">Status</label>
                        <select wire:model="newSubscription.status" class="w-full text-[12px] border border-[var(--ui-border)]/60 rounded px-2 py-1">
                            <option value="subscribed">Abonniert</option>
                            <option value="muted">Stumm</option>
                            <option value="unsubscribed">Abbestellt</option>
                        </select>
                    </div>
                    <x-ui-button type="submit" variant="primary" size="sm" class="w-full justify-center">
                        @svg('heroicon-o-plus', 'w-3.5 h-3.5')
                        <span>Hinzufügen</span>
                    </x-ui-button>
                </form>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 min-w-0 min-h-0 flex flex-col overflow-auto p-6 space-y-6">

        @if($groups->isEmpty())
            <div class="py-12 text-center rounded-lg border border-dashed border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-bell', 'w-8 h-8 text-[var(--ui-muted)] mx-auto mb-3')
                <p class="text-sm text-[var(--ui-muted)]">Noch keine Absender — beim nächsten Inbox-Ingest werden sie automatisch hinzugefügt.</p>
            </div>
        @else
            @foreach($groups as $group)
                <section>
                    <div class="flex items-center gap-2 mb-3 px-1">
                        @svg($group['icon'], 'w-4 h-4 text-[var(--ui-muted)]')
                        <h3 class="text-xs font-bold text-[var(--ui-secondary)] uppercase tracking-wider">{{ $group['label'] }}</h3>
                        <span class="text-[10px] text-[var(--ui-muted)]">({{ $group['subs']->count() }})</span>
                    </div>
                    <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg divide-y divide-[var(--ui-border)]/40">
                        @foreach($group['subs'] as $sub)
                            @php
                                $volume = $volumes[$sub->sender_kind . ':' . $sub->sender_identifier] ?? 0;
                                $statusVal = $sub->status?->value;
                            @endphp
                            <div class="flex items-center gap-3 px-4 py-3 hover:bg-[var(--ui-muted-5)]/50 transition-colors">
                                <button wire:click="toggleVip({{ $sub->id }})" title="VIP umschalten"
                                        class="flex-shrink-0 p-1 rounded hover:bg-yellow-50">
                                    @if($sub->is_vip)
                                        @svg('heroicon-s-star', 'w-4 h-4 text-yellow-500')
                                    @else
                                        @svg('heroicon-o-star', 'w-4 h-4 text-[var(--ui-muted)]/40')
                                    @endif
                                </button>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-[var(--ui-secondary)] truncate">
                                        {{ $sub->label ?: $sub->sender_identifier }}
                                    </div>
                                    @if($sub->label && $sub->label !== $sub->sender_identifier)
                                        <div class="text-[11px] text-[var(--ui-muted)] truncate">{{ $sub->sender_identifier }}</div>
                                    @endif
                                </div>
                                <div class="text-right text-[11px] flex-shrink-0 min-w-20">
                                    <div class="text-[var(--ui-secondary)] tabular-nums font-medium">{{ $volume }}</div>
                                    <div class="text-[10px] text-[var(--ui-muted)]">letzten 30d</div>
                                </div>
                                <div class="text-right text-[11px] flex-shrink-0 min-w-24">
                                    <div class="text-[var(--ui-muted)]">{{ $sub->last_seen_at?->diffForHumans() ?? '—' }}</div>
                                </div>
                                {{-- Segmented toggle --}}
                                <div class="inline-flex rounded-md border border-[var(--ui-border)]/60 overflow-hidden flex-shrink-0">
                                    <button wire:click="setStatus({{ $sub->id }}, 'subscribed')"
                                            title="Abonniert"
                                            class="px-2.5 py-1 text-[11px] transition-colors {{ $statusVal === 'subscribed' ? 'bg-green-100 text-green-800 font-medium' : 'text-[var(--ui-muted)] hover:bg-[var(--ui-muted-5)]' }}">
                                        Abo
                                    </button>
                                    <button wire:click="setStatus({{ $sub->id }}, 'muted')"
                                            title="Stumm"
                                            class="px-2.5 py-1 text-[11px] border-l border-[var(--ui-border)]/60 transition-colors {{ $statusVal === 'muted' ? 'bg-yellow-100 text-yellow-800 font-medium' : 'text-[var(--ui-muted)] hover:bg-[var(--ui-muted-5)]' }}">
                                        Stumm
                                    </button>
                                    <button wire:click="setStatus({{ $sub->id }}, 'unsubscribed')"
                                            title="Abbestellt"
                                            class="px-2.5 py-1 text-[11px] border-l border-[var(--ui-border)]/60 transition-colors {{ $statusVal === 'unsubscribed' ? 'bg-red-100 text-red-800 font-medium' : 'text-[var(--ui-muted)] hover:bg-[var(--ui-muted-5)]' }}">
                                        Aus
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif

    </div>
</x-ui-page>
