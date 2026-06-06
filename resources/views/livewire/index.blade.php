<div class="p-6">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold">Inbox</h1>
        <div class="flex gap-2">
            <select wire:model.live="channel" class="text-sm border rounded px-2 py-1">
                <option value="">Alle Kanäle</option>
                <option value="mail">E-Mail</option>
                <option value="call">Anruf</option>
                <option value="message">Nachricht</option>
                <option value="meeting">Meeting</option>
            </select>
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Suche..."
                class="text-sm border rounded px-2 py-1"
            />
        </div>
    </div>

    @if($this->items->isEmpty())
        <div class="py-10 text-center text-sm text-gray-500 border border-dashed rounded">
            Keine offenen Inbox-Einträge.
        </div>
    @else
        <div class="space-y-2">
            @foreach($this->items as $item)
                <div class="flex items-center gap-3 p-3 bg-white border rounded hover:shadow-sm">
                    <div class="text-xs uppercase tracking-wider text-gray-500 w-20">
                        {{ $item->channel?->label() }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <a href="{{ route('inbox.items.show', $item) }}" class="block">
                            <div class="text-sm font-medium truncate">
                                {{ $item->subject ?: $item->sender_label ?: $item->sender_identifier ?: '(ohne Betreff)' }}
                            </div>
                            <div class="text-xs text-gray-500 truncate">
                                {{ $item->sender_label ?: $item->sender_identifier }}
                                @if($item->preview)
                                    — {{ Str::limit($item->preview, 120) }}
                                @endif
                            </div>
                        </a>
                    </div>
                    <div class="text-xs text-gray-400 whitespace-nowrap">
                        {{ $item->received_at?->diffForHumans() }}
                    </div>
                    <div class="flex gap-1">
                        <button wire:click="markDone({{ $item->id }})" class="text-xs px-2 py-1 border rounded hover:bg-green-50">Erledigt</button>
                        <button wire:click="snooze({{ $item->id }}, 4)" class="text-xs px-2 py-1 border rounded hover:bg-yellow-50">Snooze 4h</button>
                        <button wire:click="ignore({{ $item->id }})" class="text-xs px-2 py-1 border rounded hover:bg-gray-100">Ignorieren</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
