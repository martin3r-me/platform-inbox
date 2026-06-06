<x-ui-page x-data="{}">
    <x-slot name="navbar">
        <x-ui-page-navbar :title="$item->subject ?: '(ohne Betreff)'" :icon="$item->channel?->icon() ?? 'heroicon-o-inbox'" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Inbox', 'href' => route('inbox.index'), 'icon' => 'inbox'],
            ['label' => Str::limit($item->subject ?: $item->sender_identifier ?: 'Eintrag', 40)],
        ]">
            <x-ui-button variant="ghost" size="sm" wire:click="markDone">
                @svg('heroicon-o-check', 'w-4 h-4')
                <span>Erledigt markieren</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Kontext" icon="heroicon-o-information-circle" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-5 bg-[var(--ui-muted-5)]">

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Absender</h3>
                    <p class="text-[12px] text-[var(--ui-secondary)] m-0">
                        <strong>{{ $item->sender_label ?: $item->sender_identifier ?: '—' }}</strong>
                    </p>
                    @if($item->sender_label && $item->sender_identifier)
                        <p class="text-[10px] text-[var(--ui-muted)] mt-1 m-0">{{ $item->sender_identifier }}</p>
                    @endif
                </section>

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Metadaten</h3>
                    <dl class="text-[11px] space-y-1 m-0">
                        <div class="flex justify-between"><dt class="text-[var(--ui-muted)]">Kanal</dt><dd class="text-[var(--ui-secondary)]">{{ $item->channel?->label() }}</dd></div>
                        <div class="flex justify-between"><dt class="text-[var(--ui-muted)]">Richtung</dt><dd class="text-[var(--ui-secondary)]">{{ $item->direction ?: '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-[var(--ui-muted)]">Empfangen</dt><dd class="text-[var(--ui-secondary)]">{{ $item->received_at?->format('d.m.Y H:i') ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-[var(--ui-muted)]">Status</dt><dd class="text-[var(--ui-secondary)]">{{ $item->status?->label() }}</dd></div>
                    </dl>
                </section>

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Aktionen</h3>
                    <p class="text-[11px] text-[var(--ui-muted)] m-0">
                        Hand-Off zu Helpdesk-Ticket / CRM-Contact / Entity-Verknüpfung folgen in den nächsten Iterationen.
                    </p>
                </section>

            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Quelle" icon="heroicon-o-link" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3">
                <section>
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Original-Session</h3>
                    <dl class="text-[11px] space-y-1 m-0">
                        <div class="flex justify-between gap-2"><dt class="text-[var(--ui-muted)]">Typ</dt><dd class="text-[var(--ui-secondary)] truncate">{{ $item->source_type }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-[var(--ui-muted)]">ID</dt><dd class="text-[var(--ui-secondary)] tabular-nums">{{ $item->source_id }}</dd></div>
                    </dl>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 min-w-0 min-h-0 flex flex-col overflow-auto p-6 space-y-6 max-w-3xl">
        <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-5">
            @if($item->preview)
                <div class="text-sm text-[var(--ui-secondary)] whitespace-pre-line leading-relaxed">{{ $item->preview }}</div>
            @else
                <p class="text-sm text-[var(--ui-muted)] italic m-0">Keine Vorschau verfügbar.</p>
            @endif
        </div>

        <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-5 space-y-3">
            <h2 class="text-sm font-semibold text-[var(--ui-secondary)] flex items-center gap-2">
                @svg('heroicon-o-pencil-square', 'w-4 h-4 text-[var(--ui-primary)]')
                Antworten
            </h2>
            <input type="text" wire:model="composeSubject" class="w-full text-sm border border-[var(--ui-border)]/60 rounded px-3 py-2" placeholder="Betreff" />
            <textarea wire:model="composeBody" rows="6" class="w-full text-sm border border-[var(--ui-border)]/60 rounded px-3 py-2" placeholder="Nachricht..."></textarea>
            <div class="flex justify-between items-center">
                <span class="text-[11px] text-[var(--ui-muted)]">
                    Versand erfolgt über den User-Connector — die Antwort landet auch in deinem Original-Posteingang.
                </span>
                <x-ui-button variant="primary" size="sm" wire:click="send">
                    @svg('heroicon-o-paper-airplane', 'w-4 h-4')
                    <span>Senden</span>
                </x-ui-button>
            </div>
        </div>
    </div>
</x-ui-page>
