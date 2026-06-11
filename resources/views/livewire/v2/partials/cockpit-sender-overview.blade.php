@php
    $row = collect($stream)->first(
        fn ($r) => $this->senderKey === $r['sender_kind'] . '|' . $r['sender_identifier']
    );
@endphp

@if(!$row)
    @include('inbox::livewire.v2.partials.cockpit-empty')
@else
    <div class="p-6 max-w-3xl mx-auto">
        {{-- Sender header --}}
        <div class="mb-4">
            <div class="flex items-center gap-2 mb-1">
                <h2 class="text-[18px] font-semibold text-[var(--ui-primary)] m-0">
                    {{ $row['sender_label'] }}
                </h2>
                @if($row['score'] >= 20)
                    <span class="text-[11px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 font-medium tabular-nums">
                        💎 {{ number_format($row['score'], 1) }}
                    </span>
                @endif
                @if($row['awaiting'])
                    <span class="text-[11px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-800">
                        ↑ wartet auf Dich
                    </span>
                @endif
            </div>
            <div class="text-[11px] text-[var(--ui-muted)]">
                {{ $row['sender_identifier'] }} · {{ ucfirst($row['sender_kind']) }}
            </div>
        </div>

        {{-- Pulse-Bar (14d sparkline) --}}
        @if(!empty($row['pulse_14d']))
            <div class="mb-4 p-3 rounded border border-[var(--ui-border)]/40 bg-[var(--ui-muted-5)]/40">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)]">
                        Pulse 14 Tage
                    </span>
                    <span class="text-[10px] text-[var(--ui-muted)]">
                        {{ array_sum($row['pulse_14d']) }} Aktivitäten
                    </span>
                </div>
                @php
                    $max = max(array_values($row['pulse_14d']) ?: [1]);
                @endphp
                <div class="flex items-end gap-0.5 h-8">
                    @for($i = 13; $i >= 0; $i--)
                        @php
                            $day = now()->subDays($i)->format('Y-m-d');
                            $count = $row['pulse_14d'][$day] ?? 0;
                            $height = $max > 0 ? max(2, round($count / $max * 100)) : 2;
                        @endphp
                        <div
                            class="flex-1 rounded-t bg-[var(--ui-primary)]/30"
                            style="height: {{ $height }}%"
                            title="{{ $day }}: {{ $count }}"
                        ></div>
                    @endfor
                </div>
            </div>
        @endif

        {{-- Threads --}}
        <div class="mb-4">
            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">
                Threads ({{ count($row['threads']) }})
            </h3>
            <div class="space-y-1.5">
                @foreach($row['threads'] as $thread)
                    @php
                        $threadKey = $thread['thread_key'] ?: ('item-' . $thread['latest_item_id']);
                        $channelIcon = match($thread['channel']) {
                            'mail' => 'envelope',
                            'message' => 'chat-bubble-left-right',
                            'call' => 'phone',
                            'meeting' => 'calendar-days',
                            default => 'document',
                        };
                    @endphp
                    <button
                        wire:click="selectThread('{{ $row['sender_kind'] . '|' . $row['sender_identifier'] }}', @js($threadKey))"
                        class="w-full text-left p-3 rounded border border-[var(--ui-border)]/40 bg-white hover:border-[var(--ui-primary)]/40 hover:shadow-sm transition"
                    >
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <div class="flex items-center gap-1.5 min-w-0">
                                @svg('heroicon-o-' . $channelIcon, 'w-3.5 h-3.5 text-[var(--ui-muted)] shrink-0')
                                <span class="text-[12px] font-medium text-[var(--ui-primary)] truncate">
                                    {{ $thread['subject'] ?: '(ohne Betreff)' }}
                                </span>
                                @if($thread['item_count'] > 1)
                                    <span class="text-[9px] px-1 py-px rounded bg-[var(--ui-muted-5)] tabular-nums">
                                        ×{{ $thread['item_count'] }}
                                    </span>
                                @endif
                                @if($thread['awaiting'])
                                    <span class="text-[9px] text-amber-600">↑</span>
                                @endif
                            </div>
                            <span class="text-[10px] text-[var(--ui-muted)] shrink-0 tabular-nums">
                                @if($thread['received_at'])
                                    {{ \Carbon\Carbon::parse($thread['received_at'])->diffForHumans(null, true) }}
                                @endif
                            </span>
                        </div>
                        @if($thread['preview'])
                            <p class="text-[11px] text-[var(--ui-secondary)] leading-snug line-clamp-1 m-0">
                                {{ \Illuminate\Support\Str::limit(strip_tags($thread['preview']), 120) }}
                            </p>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Channel-Mix bar --}}
        @if(!empty($row['channel_mix']))
            <div class="text-[10px] text-[var(--ui-muted)] pt-3 border-t border-[var(--ui-border)]/30">
                Kanal-Mix:
                @foreach($row['channel_mix'] as $ch => $frac)
                    @if($frac >= 0.05)
                        <span class="ml-1">{{ ucfirst($ch) }} {{ round($frac * 100) }}%</span>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
@endif
