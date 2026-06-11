@php
    $data = $this->cockpitData;
@endphp

@if(!$data)
    @include('inbox::livewire.v2.partials.cockpit-empty')
@else
    @php
        $channel = $data['item']->channel?->value;
        $partial = match($channel) {
            'mail' => 'inbox::livewire.v2.partials.cockpit-mail-thread',
            'message' => 'inbox::livewire.v2.partials.cockpit-mail-thread', // bis (f)
            'call' => 'inbox::livewire.v2.partials.cockpit-mail-thread',    // bis (g)
            'meeting' => 'inbox::livewire.v2.partials.cockpit-mail-thread', // bis (g)
            default => 'inbox::livewire.v2.partials.cockpit-mail-thread',
        };
    @endphp
    @include($partial, ['data' => $data])
@endif
