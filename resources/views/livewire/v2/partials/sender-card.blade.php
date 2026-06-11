@php
    $senderKey = $row['sender_kind'] . '|' . $row['sender_identifier'];
    $isExpanded = $expandedSenderKey === $senderKey;
    $isActive = $senderKey === $senderKey && $senderKey === ($this->senderKey ?? null);
    $threadCount = count($row['threads']);
    $totalMessages = array_sum(array_column($row['threads'], 'item_count'));
    $score = (float) $row['score'];
@endphp

<div
    wire:key="sender-{{ $senderKey }}"
    class="rounded-md border transition cursor-pointer overflow-hidden
        {{ $isActive
            ? 'border-[var(--ui-primary)]/40 bg-white ring-1 ring-[var(--ui-primary)]/20 shadow-sm'
            : 'border-[var(--ui-border)]/40 bg-white hover:border-[var(--ui-border)]' }}"
    wire:click="selectSender('{{ $senderKey }}')"
>
    {{-- Sender header ----------------------------------------------- --}}
    <div class="p-3">
        <div class="flex items-start justify-between gap-2 mb-1">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-1.5 mb-0.5">
                    @if($row['awaiting'])
                        <span class="text-[10px] text-amber-600" title="Wartet auf Deine Antwort">↑</span>
                    @endif
                    <span class="text-[13px] font-semibold text-[var(--ui-primary)] truncate">
                        {{ $row['sender_label'] }}
                    </span>
                    @if($score >= 20)
                        <span class="text-[9px] px-1 py-px rounded bg-amber-50 text-amber-700 font-medium tabular-nums" title="Importance-Score">
                            💎 {{ number_format($score, 1) }}
                        </span>
                    @endif
                </div>
                <div class="flex items-center gap-2 text-[10px] text-[var(--ui-muted)]">
                    <span>{{ $threadCount }} {{ $threadCount === 1 ? 'Thread' : 'Threads' }}</span>
                    <span>·</span>
                    <span>{{ $totalMessages }} {{ $totalMessages === 1 ? 'Nachricht' : 'Nachrichten' }}</span>
                    @if(!empty($row['channel_mix']))
                        <span>·</span>
                        @foreach($row['channel_mix'] as $ch => $frac)
                            @if($frac >= 0.1)
                                <span class="opacity-80">{{ ucfirst($ch) }} {{ round($frac * 100) }}%</span>
                                @if(!$loop->last) <span>·</span> @endif
                            @endif
                        @endforeach
                    @endif
                </div>
            </div>
            <div class="text-[10px] text-[var(--ui-muted)] shrink-0 text-right">
                @if($row['last_at'])
                    <time datetime="{{ $row['last_at'] }}">
                        {{ \Carbon\Carbon::parse($row['last_at'])->diffForHumans(null, true) }}
                    </time>
                @endif
                <button
                    wire:click.stop="toggleExpand('{{ $senderKey }}')"
                    class="block mt-1 text-[var(--ui-muted)] hover:text-[var(--ui-primary)]"
                    title="Threads {{ $isExpanded ? 'einklappen' : 'aufklappen' }} (e)"
                >
                    @if($isExpanded)
                        @svg('heroicon-o-chevron-up', 'w-3 h-3 inline')
                    @else
                        @svg('heroicon-o-chevron-down', 'w-3 h-3 inline')
                    @endif
                </button>
            </div>
        </div>
    </div>

    {{-- Threads (only when expanded) ------------------------------- --}}
    @if($isExpanded || $threadCount === 1)
        <div class="border-t border-[var(--ui-border)]/30 bg-[var(--ui-muted-5)]/30">
            @foreach($row['threads'] as $thread)
                @php
                    $threadKey = $thread['thread_key'] ?: ('item-' . $thread['latest_item_id']);
                    $isActiveThread = $this->threadKey === $threadKey;
                    $channelIcon = match($thread['channel']) {
                        'mail' => 'envelope',
                        'message' => 'chat-bubble-left-right',
                        'call' => 'phone',
                        'meeting' => 'calendar-days',
                        default => 'document',
                    };
                    $channelColor = match($thread['channel']) {
                        'mail' => 'border-l-slate-400',
                        'message' => 'border-l-indigo-400',
                        'call' => 'border-l-amber-400',
                        'meeting' => 'border-l-emerald-400',
                        default => 'border-l-violet-400',
                    };
                @endphp
                <div
                    wire:key="thread-{{ $senderKey }}-{{ $threadKey }}"
                    wire:click.stop="selectThread('{{ $senderKey }}', @js($threadKey))"
                    class="px-3 py-2 border-l-2 {{ $channelColor }} cursor-pointer transition
                        {{ $isActiveThread
                            ? 'bg-[var(--ui-primary)]/5 ring-1 ring-inset ring-[var(--ui-primary)]/20'
                            : 'hover:bg-white' }}
                        @if(!$loop->last) border-b border-[var(--ui-border)]/30 @endif"
                >
                    <div class="flex items-center justify-between gap-2 mb-0.5">
                        <div class="flex items-center gap-1.5 min-w-0">
                            @svg('heroicon-o-' . $channelIcon, 'w-3 h-3 text-[var(--ui-muted)] shrink-0')
                            @if($thread['awaiting'])
                                <span class="text-[9px] text-amber-600">↑</span>
                            @endif
                            <span class="text-[11.5px] font-medium text-[var(--ui-primary)] truncate">
                                {{ $thread['subject'] ?: '(ohne Betreff)' }}
                            </span>
                            @if($thread['item_count'] > 1)
                                <span class="text-[9px] px-1 py-px rounded bg-[var(--ui-muted-5)] tabular-nums">
                                    ×{{ $thread['item_count'] }}
                                </span>
                            @endif
                        </div>
                        <span class="text-[9px] text-[var(--ui-muted)] shrink-0 tabular-nums">
                            @if($thread['received_at'])
                                {{ \Carbon\Carbon::parse($thread['received_at'])->format('H:i') }}
                            @endif
                        </span>
                    </div>
                    @if($thread['preview'])
                        <p class="text-[10.5px] text-[var(--ui-secondary)] leading-snug line-clamp-1 m-0">
                            {{ \Illuminate\Support\Str::limit(strip_tags($thread['preview']), 80) }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
