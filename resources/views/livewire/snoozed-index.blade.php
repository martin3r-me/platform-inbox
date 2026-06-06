@php
    $items = $this->items;
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
        <x-ui-page-sidebar title="Übersicht" icon="heroicon-o-information-circle" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-5 bg-[var(--ui-muted-5)]">
                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Über</h3>
                    <p class="text-[11px] text-[var(--ui-secondary)] leading-relaxed m-0">
                        Beiseite gelegte Einträge. Tauchen automatisch wieder auf, sobald die Snooze-Zeit abgelaufen ist.
                    </p>
                </section>
                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Stand</h3>
                    <p class="text-[11px] text-[var(--ui-secondary)] m-0">
                        <strong>{{ $items->count() }}</strong> auf Snooze
                    </p>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="p-6">
        @if($items->isEmpty())
            <div class="py-12 text-center rounded-lg border border-dashed border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-clock', 'w-8 h-8 text-[var(--ui-muted)] mx-auto mb-3')
                <p class="text-sm text-[var(--ui-muted)]">Nichts auf Snooze.</p>
            </div>
        @else
            <div class="space-y-2">
                @foreach($items as $item)
                    <div class="flex items-center gap-3 p-3 bg-white border border-[var(--ui-border)]/40 rounded-lg">
                        <div class="w-20 text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">{{ $item->channel?->label() }}</div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-[var(--ui-secondary)] truncate">{{ $item->subject ?: $item->sender_label ?: '(ohne Betreff)' }}</div>
                            <div class="text-[11px] text-[var(--ui-muted)]">Wieder fällig: {{ $item->snoozed_until?->diffForHumans() }}</div>
                        </div>
                        <x-ui-button variant="ghost" size="sm" wire:click="wakeUp({{ $item->id }})">
                            @svg('heroicon-o-arrow-uturn-left', 'w-3.5 h-3.5')
                            <span>Wieder zeigen</span>
                        </x-ui-button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-ui-page>
