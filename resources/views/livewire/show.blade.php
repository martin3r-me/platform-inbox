<div class="p-6 max-w-3xl mx-auto space-y-4">
    <a href="{{ route('inbox.index') }}" class="text-sm text-gray-500">&larr; Zurück zur Inbox</a>

    <div class="bg-white border rounded p-4">
        <div class="text-xs uppercase text-gray-500 mb-1">{{ $item->channel?->label() }} · {{ $item->direction }}</div>
        <h1 class="text-lg font-semibold">{{ $item->subject ?: '(ohne Betreff)' }}</h1>
        <div class="text-sm text-gray-600">{{ $item->sender_label ?: $item->sender_identifier }}</div>
        <div class="text-xs text-gray-400 mt-1">{{ $item->received_at?->format('d.m.Y H:i') }}</div>

        @if($item->preview)
            <div class="mt-4 text-sm whitespace-pre-line border-t pt-4">{{ $item->preview }}</div>
        @endif
    </div>

    <div class="bg-white border rounded p-4">
        <h2 class="text-sm font-semibold mb-2">Antworten</h2>
        <input type="text" wire:model="composeSubject" class="w-full text-sm border rounded px-2 py-1 mb-2" placeholder="Betreff" />
        <textarea wire:model="composeBody" rows="6" class="w-full text-sm border rounded px-2 py-1" placeholder="Nachricht…"></textarea>
        <div class="flex justify-between items-center mt-2">
            <span class="text-xs text-gray-500">Versand erfolgt über den User-Connector — die Antwort landet auch in deinem Original-Posteingang.</span>
            <div class="flex gap-2">
                <button wire:click="markDone" class="text-sm px-3 py-1 border rounded">Erledigt markieren</button>
                <button wire:click="send" class="text-sm px-3 py-1 bg-blue-600 text-white rounded">Senden</button>
            </div>
        </div>
    </div>
</div>
