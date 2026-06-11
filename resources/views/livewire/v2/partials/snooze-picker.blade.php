@if($this->snoozePickerOpen)
<div
    wire:click="toggleSnoozePicker"
    class="fixed inset-0 z-40 bg-black/30 flex items-end sm:items-center justify-center p-4"
>
    <div
        wire:click.stop=""
        class="w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden"
    >
        <div class="px-5 py-3 border-b border-[var(--ui-border)]/30 flex items-center justify-between">
            <h3 class="text-[13px] font-semibold text-[var(--ui-primary)] m-0 flex items-center gap-2">
                @svg('heroicon-o-moon', 'w-4 h-4 text-amber-500')
                Snooze bis …
            </h3>
            <button
                wire:click="toggleSnoozePicker"
                class="text-[var(--ui-muted)] hover:text-[var(--ui-primary)]"
            >
                @svg('heroicon-o-x-mark', 'w-4 h-4')
            </button>
        </div>
        <div class="p-3 space-y-1">
            @foreach($this->snoozePresets() as $preset)
                <button
                    wire:click="snoozeUntil('{{ $preset['key'] }}')"
                    class="w-full px-3 py-2.5 rounded-lg text-left flex items-center justify-between gap-3 hover:bg-[var(--ui-muted-5)] transition group"
                >
                    <span class="text-[12.5px] text-[var(--ui-primary)] font-medium">
                        {{ $preset['label'] }}
                    </span>
                    <span class="text-[10.5px] text-[var(--ui-muted)] tabular-nums group-hover:text-[var(--ui-secondary)] transition">
                        {{ $preset['at']->locale('de')->isoFormat('dd, DD.MM.') }}
                        {{ $preset['at']->format('H:i') }}
                    </span>
                </button>
            @endforeach
        </div>
    </div>
</div>
@endif
