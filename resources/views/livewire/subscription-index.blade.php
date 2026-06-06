<div class="p-6">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold">Abonnements</h1>
        <select wire:model.live="filterStatus" class="text-sm border rounded px-2 py-1">
            <option value="">Alle Status</option>
            <option value="subscribed">Abonniert</option>
            <option value="unsubscribed">Abbestellt</option>
            <option value="muted">Stumm</option>
        </select>
    </div>

    @if($this->subscriptions->isEmpty())
        <div class="py-10 text-center text-sm text-gray-500 border border-dashed rounded">
            Noch keine Absender-Abonnements.
        </div>
    @else
        <table class="w-full text-sm">
            <thead class="text-xs uppercase text-gray-500">
                <tr>
                    <th class="text-left py-2">Absender</th>
                    <th class="text-left py-2">Kanal</th>
                    <th class="text-left py-2">Status</th>
                    <th class="text-left py-2">Zuletzt</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($this->subscriptions as $sub)
                    <tr class="border-t">
                        <td class="py-2">{{ $sub->label ?: $sub->sender_identifier }}</td>
                        <td class="py-2 text-xs uppercase text-gray-500">{{ $sub->sender_kind }}</td>
                        <td class="py-2">{{ $sub->status?->label() }}</td>
                        <td class="py-2 text-xs text-gray-500">{{ $sub->last_seen_at?->diffForHumans() }}</td>
                        <td class="py-2 text-right space-x-1">
                            <button wire:click="setStatus({{ $sub->id }}, 'subscribed')" class="text-xs px-2 py-1 border rounded">Abonnieren</button>
                            <button wire:click="setStatus({{ $sub->id }}, 'muted')" class="text-xs px-2 py-1 border rounded">Stumm</button>
                            <button wire:click="setStatus({{ $sub->id }}, 'unsubscribed')" class="text-xs px-2 py-1 border rounded">Abbestellen</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
