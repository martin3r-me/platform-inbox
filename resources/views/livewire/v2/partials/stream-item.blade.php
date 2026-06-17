@php
    $isActive = $this->threadKey === $item['thread_key']
        && $this->senderKey === $item['sender_key'];

    // Channel chip: stronger visual differentiation than the old border-left.
    // Each channel gets a tinted background + matching icon color so the
    // user can scan the channel mix at a glance.
    $channelChip = match ($item['channel']) {
        'mail'    => ['icon' => 'envelope',                'bg' => 'bg-slate-100',   'fg' => 'text-slate-700',   'label' => 'Mail'],
        'message' => ['icon' => 'chat-bubble-left-right',  'bg' => 'bg-indigo-100',  'fg' => 'text-indigo-700',  'label' => 'Chat'],
        'call'    => ['icon' => 'phone',                   'bg' => 'bg-amber-100',   'fg' => 'text-amber-800',   'label' => 'Call'],
        'meeting' => ['icon' => 'calendar-days',           'bg' => 'bg-emerald-100', 'fg' => 'text-emerald-800', 'label' => 'Meeting'],
        default   => ['icon' => 'document',                'bg' => 'bg-violet-100',  'fg' => 'text-violet-700',  'label' => ucfirst($item['channel'] ?? '?')],
    };

    // Time format depends on bucket — within today we only need H:i; for
    // older / upcoming we want a date so the user can place it without
    // hovering. The view passes $bucketKey in via @include.
    $recAt = $item['received_at'] ? \Carbon\Carbon::parse($item['received_at']) : null;
    $timeLabel = null;
    if ($recAt) {
        $timeLabel = match ($bucketKey ?? '') {
            'today', 'yesterday' => $recAt->format('H:i'),
            'upcoming'           => $recAt->isToday()
                ? $recAt->format('H:i')
                : ($recAt->isCurrentYear() ? $recAt->format('d. M, H:i') : $recAt->format('d.m.Y')),
            default              => $recAt->isCurrentYear()
                ? $recAt->format('d. M')
                : $recAt->format('d.m.Y'),
        };
    }
@endphp

<button
    type="button"
    wire:key="stream-item-{{ $item['id'] }}"
    wire:click="selectItem({{ $item['id'] }})"
    class="w-full flex items-start gap-2 px-3 py-2 rounded-md border text-left transition
        {{ $isActive
            ? 'border-[var(--ui-primary)]/40 bg-white ring-1 ring-[var(--ui-primary)]/20 shadow-sm'
            : 'border-transparent bg-white hover:border-[var(--ui-border)]/60 hover:bg-white' }}"
>
    {{-- Channel chip ----------------------------------------------- --}}
    <span
        class="shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-md {{ $channelChip['bg'] }} {{ $channelChip['fg'] }}"
        title="{{ $channelChip['label'] }}"
    >
        @svg('heroicon-o-' . $channelChip['icon'], 'w-4 h-4')
    </span>

    <div class="min-w-0 flex-1">
        {{-- Row 1: sender + status + time -------------------------------- --}}
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-1.5 min-w-0">
                @if($item['awaiting'])
                    <span class="text-[10px] text-amber-600 shrink-0" title="Wartet auf Deine Antwort">↑</span>
                @endif
                <span class="text-[12.5px] font-semibold text-[var(--ui-primary)] truncate">
                    {{ $item['sender_label'] }}
                </span>
                @if(($item['importance_score'] ?? 0) >= 20)
                    <span
                        class="text-[9px] px-1 py-px rounded bg-amber-50 text-amber-700 font-medium tabular-nums shrink-0"
                        title="Importance-Score"
                    >
                        💎
                    </span>
                @endif
            </div>
            @if($timeLabel)
                <time
                    datetime="{{ $item['received_at'] }}"
                    class="text-[10px] text-[var(--ui-muted)] shrink-0 tabular-nums"
                >
                    {{ $timeLabel }}
                </time>
            @endif
        </div>

        {{-- Row 2: subject ---------------------------------------------- --}}
        <div class="text-[11.5px] text-[var(--ui-primary)] truncate">
            {{ $item['subject'] ?: '(ohne Betreff)' }}
        </div>

        {{-- Row 3: preview (only if present) ---------------------------- --}}
        @if($item['preview'])
            <p class="text-[10.5px] text-[var(--ui-secondary)] leading-snug line-clamp-1 m-0 mt-0.5">
                {{ \Illuminate\Support\Str::limit(strip_tags($item['preview']), 90) }}
            </p>
        @endif
    </div>
</button>
