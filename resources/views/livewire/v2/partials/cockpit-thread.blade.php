@php
    $data = $this->cockpitData;
@endphp

@if(!$data)
    @include('inbox::livewire.v2.partials.cockpit-empty')
@else
    @php
        $channel = $data['item']->channel?->value;
        // Audio recordings can ride on the 'mail' channel (Plaud) or have their
        // own 'voice' channel — both have audio_duration_seconds set. Detect
        // by data, not by channel name, so future audio sources just work.
        $isAudio = (int) ($data['item']->audio_duration_seconds ?? 0) > 0;

        $partial = match (true) {
            $isAudio => 'inbox::livewire.v2.partials.cockpit-voice',
            $channel === 'mail' => 'inbox::livewire.v2.partials.cockpit-mail-thread',
            $channel === 'message' => 'inbox::livewire.v2.partials.cockpit-chat-thread',
            $channel === 'call' => 'inbox::livewire.v2.partials.cockpit-call',
            $channel === 'meeting' => 'inbox::livewire.v2.partials.cockpit-meeting',
            default => 'inbox::livewire.v2.partials.cockpit-mail-thread',
        };
    @endphp
    @include($partial, ['data' => $data])
@endif
