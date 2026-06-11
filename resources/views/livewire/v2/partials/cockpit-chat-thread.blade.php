@php
    /** @var array $data */
    $item = $data['item'];
    $enrichment = $data['enrichment'] ?? null;
    $linked = $data['linked_entities'] ?? [];
    $messages = $data['thread_history']['messages'] ?? [];
    $status = $item->status?->value;

    // Pull body from cached InboxItem when the chat_thread history is empty —
    // happens for chats that arrived before the per-message detail table was
    // populated.
    if (empty($messages) && $item->body) {
        $messages = [[
            'from' => $item->sender_label ?: $item->sender_identifier,
            'direction' => $item->direction,
            'body' => $item->body,
            'preview' => $item->preview,
            'sent_at' => $item->received_at?->toIso8601String(),
        ]];
    }
@endphp

<div class="h-full flex flex-col">

    {{-- STICKY HEADER -------------------------------------------- --}}
    <div class="shrink-0 bg-white border-b border-[var(--ui-border)]/40">
        <div class="px-6 py-4">
            <div class="flex items-start justify-between gap-3 mb-2">
                <h2 class="text-[16px] font-semibold text-[var(--ui-primary)] m-0 leading-snug">
                    @svg('heroicon-o-chat-bubble-left-right', 'w-4 h-4 inline mr-1 -mt-px text-indigo-500')
                    Chat mit {{ $item->sender_label ?: $item->sender_identifier }}
                </h2>
                <div class="shrink-0 text-[10px] text-[var(--ui-muted)] tabular-nums">
                    {{ count($messages) }} {{ count($messages) === 1 ? 'Nachricht' : 'Nachrichten' }}
                </div>
            </div>
            <div class="flex items-center gap-2 text-[11px] text-[var(--ui-secondary)] flex-wrap">
                <span>{{ $item->sender_identifier }}</span>
                @if($status && $status !== 'new')
                    <span class="px-1.5 py-0.5 rounded text-[10px] font-medium
                        {{ $status === 'done' ? 'bg-emerald-50 text-emerald-700' : '' }}
                        {{ $status === 'snoozed' ? 'bg-amber-50 text-amber-700' : '' }}">
                        {{ ucfirst($status) }}
                    </span>
                @endif
                @foreach($linked as $entity)
                    <span class="px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 text-[10px]">
                        🔗 {{ $entity['name'] ?: '#' . $entity['id'] }}
                    </span>
                @endforeach
            </div>
        </div>

        {{-- Action strip --}}
        <div class="flex items-center gap-1 px-6 py-2 border-t border-[var(--ui-border)]/30 bg-[var(--ui-muted-5)]/30">
            <button wire:click="markDone"
                class="px-2.5 py-1 rounded text-[11px] font-medium text-emerald-700 hover:bg-emerald-50 transition flex items-center gap-1"
                title="Done — d">
                @svg('heroicon-o-check-circle', 'w-3.5 h-3.5')<span>Done</span><kbd class="text-[9px] opacity-60 ml-1">d</kbd>
            </button>
            <button wire:click="snooze(4)"
                class="px-2.5 py-1 rounded text-[11px] font-medium text-amber-700 hover:bg-amber-50 transition flex items-center gap-1"
                title="Snooze 4h — s">
                @svg('heroicon-o-moon', 'w-3.5 h-3.5')<span>Snooze 4h</span><kbd class="text-[9px] opacity-60 ml-1">s</kbd>
            </button>
            <button disabled class="px-2.5 py-1 rounded text-[11px] text-[var(--ui-muted)] cursor-not-allowed flex items-center gap-1 opacity-50">
                @svg('heroicon-o-arrow-uturn-right', 'w-3.5 h-3.5')<span>Handoff</span><kbd class="text-[9px] opacity-60 ml-1">h</kbd>
            </button>
            <button disabled class="px-2.5 py-1 rounded text-[11px] text-[var(--ui-muted)] cursor-not-allowed flex items-center gap-1 opacity-50">
                @svg('heroicon-o-link', 'w-3.5 h-3.5')<span>Link</span><kbd class="text-[9px] opacity-60 ml-1">l</kbd>
            </button>
            <div class="flex-1"></div>
            <button wire:click="openReply" class="px-2.5 py-1 rounded text-[11px] font-medium text-[var(--ui-primary)] hover:bg-[var(--ui-primary)]/10 transition flex items-center gap-1" title="Reply — Taste r">
                @svg('heroicon-o-arrow-uturn-left', 'w-3.5 h-3.5')<span>Reply</span><kbd class="text-[9px] opacity-60 ml-1">r</kbd>
            </button>
        </div>
    </div>

    {{-- CHAT BUBBLES --------------------------------------------- --}}
    <div
        class="flex-1 overflow-y-auto px-4 py-4 bg-[var(--ui-muted-5)]/20"
        x-data
        x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
    >
        @if($enrichment && $enrichment['tldr'])
            <div class="max-w-md mx-auto mb-4 rounded-lg border border-[var(--ui-primary)]/30 bg-[var(--ui-primary)]/5 p-3 text-center">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-primary)] mb-0.5">TLDR</div>
                <p class="text-[11.5px] text-[var(--ui-secondary)] m-0 leading-snug">
                    {{ $enrichment['tldr'] }}
                </p>
            </div>
        @endif

        @if(empty($messages))
            <div class="text-center text-[12px] text-[var(--ui-muted)] py-8">
                Keine Nachrichten verfügbar.
            </div>
        @else
            <div class="space-y-1.5 max-w-2xl mx-auto">
                @php $lastFrom = null; @endphp
                @foreach($messages as $msg)
                    @php
                        $isMine = ($msg['direction'] ?? null) === 'outbound';
                        $from = $msg['from'] ?? ($msg['from_name'] ?? '?');
                        $showHeader = $from !== $lastFrom;
                        $lastFrom = $from;
                        $body = $msg['body'] ?? $msg['preview'] ?? '';
                    @endphp
                    @if($showHeader && !$isMine)
                        <div class="text-[10px] text-[var(--ui-muted)] mt-3 mb-0.5 pl-1">
                            {{ $from }}
                        </div>
                    @elseif($showHeader && $isMine)
                        <div class="text-[10px] text-[var(--ui-muted)] mt-3 mb-0.5 pr-1 text-right">
                            Du
                        </div>
                    @endif
                    <div class="flex {{ $isMine ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[75%] rounded-2xl px-3.5 py-2 text-[12.5px] leading-relaxed
                            {{ $isMine
                                ? 'bg-indigo-500 text-white rounded-br-sm'
                                : 'bg-white border border-[var(--ui-border)]/40 text-[var(--ui-secondary)] rounded-bl-sm' }}">
                            @if(stripos($body, '<') !== false && stripos($body, '>') !== false)
                                <div class="m-0">{!! strip_tags($body, '<br><b><i><strong><em><a>') !!}</div>
                            @else
                                <p class="m-0 whitespace-pre-wrap">{{ $body }}</p>
                            @endif
                            <div class="text-[9px] mt-1 {{ $isMine ? 'text-indigo-100' : 'text-[var(--ui-muted)]' }} tabular-nums text-right">
                                @if(!empty($msg['sent_at']) || !empty($msg['received_at']))
                                    {{ \Carbon\Carbon::parse($msg['sent_at'] ?? $msg['received_at'])->format('H:i') }}
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- INLINE COMPOSER ----------------------------------------- --}}
    @include('inbox::livewire.v2.partials.reply-composer', ['channel' => 'message'])
</div>
