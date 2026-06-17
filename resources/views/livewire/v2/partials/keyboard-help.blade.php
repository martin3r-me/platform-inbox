@if($this->helpOpen)
<div
    wire:click="toggleHelp"
    class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm flex items-center justify-center p-4"
>
    <div
        wire:click.stop=""
        class="max-w-2xl w-full bg-white rounded-2xl shadow-2xl overflow-hidden"
    >
        <div class="px-6 py-4 border-b border-[var(--ui-border)]/30 flex items-center justify-between">
            <h2 class="text-[14px] font-semibold text-[var(--ui-primary)] m-0">
                Tastatur-Shortcuts
            </h2>
            <button
                wire:click="toggleHelp"
                class="text-[var(--ui-muted)] hover:text-[var(--ui-primary)]"
            >
                @svg('heroicon-o-x-mark', 'w-4 h-4')
            </button>
        </div>

        <div class="grid grid-cols-2 gap-x-8 gap-y-1 p-6 text-[12px]">
            @php
                $cols = [
                    'Navigation' => [
                        ['j',         'Nächstes Item'],
                        ['k',         'Vorheriges Item'],
                        ['J',         'Nächster Absender (Sprung)'],
                        ['K',         'Vorheriger Absender (Sprung)'],
                        ['Enter',     'Nächstes Item'],
                        ['Esc',       'Auswahl löschen / Composer schließen'],
                        ['Shift+S',   'Sort: Chronologisch ↔ Smart'],
                    ],
                    'Verben' => [
                        ['d', 'Done — als erledigt markieren'],
                        ['s', 'Snooze — Picker öffnen'],
                        ['r', 'Reply — Composer öffnen'],
                        ['h', 'Handoff (Layer Folge)'],
                        ['l', 'Link Entity (Layer Folge)'],
                        ['c', 'Compose neu (Layer Folge)'],
                        ['?', 'Diese Übersicht'],
                    ],
                ];
            @endphp
            @foreach($cols as $title => $rows)
                <div>
                    <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">
                        {{ $title }}
                    </div>
                    @foreach($rows as [$key, $desc])
                        <div class="flex items-center gap-3 py-1">
                            <kbd class="shrink-0 min-w-[40px] text-center px-2 py-0.5 rounded border border-[var(--ui-border)]/60 bg-[var(--ui-muted-5)] text-[11px] font-mono">
                                {{ $key }}
                            </kbd>
                            <span class="text-[var(--ui-secondary)]">{{ $desc }}</span>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>

        <div class="px-6 py-3 bg-[var(--ui-muted-5)]/40 border-t border-[var(--ui-border)]/30 text-[10px] text-[var(--ui-muted)]">
            Beim Tippen in Composer/Such-Feldern sind Verben deaktiviert. <kbd class="px-1 py-0.5 rounded border border-[var(--ui-border)]/40 bg-white text-[9px]">Esc</kbd> verlässt das Feld.
        </div>
    </div>
</div>
@endif
