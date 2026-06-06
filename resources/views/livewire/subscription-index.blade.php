@php
    $subs = $this->subscriptions;
    $counts = [
        'subscribed' => $subs->where('status.value', 'subscribed')->count(),
        'muted' => $subs->where('status.value', 'muted')->count(),
        'unsubscribed' => $subs->where('status.value', 'unsubscribed')->count(),
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
                        Pro Absender (E-Mail, Telefonnummer, Teams-ID) regelst du hier, ob er in deiner Inbox auftaucht — und wenn ja, wie.
                    </p>
                </section>

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Status</h3>
                    <div class="space-y-1">
                        @foreach([
                            '' => ['label' => 'Alle', 'icon' => 'heroicon-o-rectangle-stack', 'count' => $subs->count()],
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

            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktivität" icon="heroicon-o-bolt" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3">
                <section>
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Zuletzt gesehen</h3>
                    @php $recent = $subs->whereNotNull('last_seen_at')->sortByDesc('last_seen_at')->take(8); @endphp
                    @if($recent->isEmpty())
                        <p class="text-[11px] text-[var(--ui-muted)] m-0">Noch keine Aktivität.</p>
                    @else
                        <ul class="space-y-1 list-none p-0 m-0">
                            @foreach($recent as $r)
                                <li class="text-[11px]">
                                    <div class="text-[var(--ui-secondary)] truncate">{{ $r->label ?: $r->sender_identifier }}</div>
                                    <div class="text-[10px] text-[var(--ui-muted)]">{{ $r->last_seen_at?->diffForHumans() }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 min-w-0 min-h-0 flex flex-col overflow-auto p-6">
        @if($subs->isEmpty())
            <div class="py-12 text-center rounded-lg border border-dashed border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-bell', 'w-8 h-8 text-[var(--ui-muted)] mx-auto mb-3')
                <p class="text-sm text-[var(--ui-muted)]">Noch keine Absender-Abonnements.</p>
            </div>
        @else
            <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)] bg-[var(--ui-muted-5)]">
                        <tr>
                            <th class="text-left px-4 py-2">Absender</th>
                            <th class="text-left px-4 py-2">Kanal</th>
                            <th class="text-left px-4 py-2">Status</th>
                            <th class="text-left px-4 py-2">Zuletzt</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($subs as $sub)
                            <tr class="border-t border-[var(--ui-border)]/40">
                                <td class="px-4 py-2 text-[var(--ui-secondary)]">{{ $sub->label ?: $sub->sender_identifier }}</td>
                                <td class="px-4 py-2 text-[10px] uppercase text-[var(--ui-muted)]">{{ $sub->sender_kind }}</td>
                                <td class="px-4 py-2 text-[var(--ui-secondary)]">{{ $sub->status?->label() }}</td>
                                <td class="px-4 py-2 text-[11px] text-[var(--ui-muted)]">{{ $sub->last_seen_at?->diffForHumans() ?? '—' }}</td>
                                <td class="px-4 py-2 text-right space-x-1 whitespace-nowrap">
                                    <button wire:click="setStatus({{ $sub->id }}, 'subscribed')" title="Abonnieren" class="px-2 py-1 text-[11px] rounded border border-[var(--ui-border)]/60 hover:bg-green-50">Abo</button>
                                    <button wire:click="setStatus({{ $sub->id }}, 'muted')" title="Stumm" class="px-2 py-1 text-[11px] rounded border border-[var(--ui-border)]/60 hover:bg-yellow-50">Stumm</button>
                                    <button wire:click="setStatus({{ $sub->id }}, 'unsubscribed')" title="Abbestellen" class="px-2 py-1 text-[11px] rounded border border-[var(--ui-border)]/60 hover:bg-[var(--ui-muted-5)]">Abbest.</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-ui-page>
