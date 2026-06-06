<div class="p-6">
    <h1 class="text-xl font-semibold mb-4">Snoozed</h1>

    @if($this->items->isEmpty())
        <div class="py-10 text-center text-sm text-gray-500 border border-dashed rounded">
            Nichts auf Snooze.
        </div>
    @else
        <div class="space-y-2">
            @foreach($this->items as $item)
                <div class="flex items-center gap-3 p-3 bg-white border rounded">
                    <div class="text-xs uppercase tracking-wider text-gray-500 w-20">{{ $item->channel?->label() }}</div>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium truncate">{{ $item->subject ?: $item->sender_label ?: '(ohne Betreff)' }}</div>
                        <div class="text-xs text-gray-500">Wieder fällig: {{ $item->snoozed_until?->diffForHumans() }}</div>
                    </div>
                    <button wire:click="wakeUp({{ $item->id }})" class="text-xs px-2 py-1 border rounded">Jetzt wieder zeigen</button>
                </div>
            @endforeach
        </div>
    @endif
</div>
