<div>
    <div class="flex items-center justify-between mb-2">
        <h3 class="text-sm font-semibold">Abbestellte Absender</h3>
        <select wire:model.live="kind" class="text-xs border rounded px-2 py-1">
            <option value="">Alle</option>
            <option value="email">E-Mail</option>
            <option value="phone">Telefon</option>
            <option value="teams">Teams</option>
        </select>
    </div>

    @if($this->unsubscribed->isEmpty())
        <div class="py-4 text-center text-xs text-gray-500 border border-dashed rounded">
            Keine abbestellten Absender.
        </div>
    @else
        <ul class="space-y-1">
            @foreach($this->unsubscribed as $sub)
                <li class="flex items-center justify-between text-xs py-1 border-b">
                    <span>
                        <span class="uppercase text-gray-400">{{ $sub->sender_kind }}</span>
                        <span class="ml-2">{{ $sub->label ?: $sub->sender_identifier }}</span>
                    </span>
                    <button wire:click="resubscribe({{ $sub->id }})" class="px-2 py-0.5 border rounded hover:bg-gray-50">Wieder abonnieren</button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
