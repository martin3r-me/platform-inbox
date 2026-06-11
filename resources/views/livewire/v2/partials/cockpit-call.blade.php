@php
    /** @var array $data */
    $item = $data['item'];
    $enrichment = $data['enrichment'] ?? null;
    $linked = $data['linked_entities'] ?? [];
    $status = $item->status?->value;
    $direction = $item->direction ?? 'inbound';
    $isMissed = $direction === 'inbound' && $status === 'new';
@endphp

<div class="h-full flex flex-col">

    {{-- STICKY HEADER -------------------------------------------- --}}
    <div class="shrink-0 bg-white border-b border-[var(--ui-border)]/40">
        <div class="px-6 py-4">
            <div class="flex items-start justify-between gap-3 mb-2">
                <h2 class="text-[16px] font-semibold text-[var(--ui-primary)] m-0 leading-snug">
                    @svg('heroicon-o-phone', 'w-4 h-4 inline mr-1 -mt-px text-amber-500')
                    @if($direction === 'inbound')
                        @if($isMissed) Verpasster Anruf @else Eingehender Anruf @endif
                    @else
                        Ausgehender Anruf
                    @endif
                </h2>
                <div class="shrink-0 text-[10px] text-[var(--ui-muted)] tabular-nums">
                    @if($item->received_at)
                        <time datetime="{{ $item->received_at->toIso8601String() }}">{{ $item->received_at->diffForHumans() }}</time>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2 text-[11px] text-[var(--ui-secondary)] flex-wrap">
                <span class="font-medium">{{ $item->sender_label ?: $item->sender_identifier ?: '(unbekannt)' }}</span>
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
        <div class="flex items-center gap-1 px-6 py-2 border-t border-[var(--ui-border)]/30 bg-[var(--ui-muted-5)]/30">
            <button wire:click="markDone" class="px-2.5 py-1 rounded text-[11px] font-medium text-emerald-700 hover:bg-emerald-50 transition flex items-center gap-1" title="Done — d">
                @svg('heroicon-o-check-circle', 'w-3.5 h-3.5')<span>Done</span><kbd class="text-[9px] opacity-60 ml-1">d</kbd>
            </button>
            <button wire:click="snooze(4)" class="px-2.5 py-1 rounded text-[11px] font-medium text-amber-700 hover:bg-amber-50 transition flex items-center gap-1" title="Snooze 4h — s">
                @svg('heroicon-o-moon', 'w-3.5 h-3.5')<span>Snooze 4h</span><kbd class="text-[9px] opacity-60 ml-1">s</kbd>
            </button>
            <button disabled class="px-2.5 py-1 rounded text-[11px] text-[var(--ui-muted)] cursor-not-allowed flex items-center gap-1 opacity-50">
                @svg('heroicon-o-link', 'w-3.5 h-3.5')<span>Link</span><kbd class="text-[9px] opacity-60 ml-1">l</kbd>
            </button>
        </div>
    </div>

    {{-- CALLER CARD --------------------------------------------- --}}
    <div class="flex-1 overflow-y-auto bg-[var(--ui-muted-5)]/20 p-6">
        <div class="max-w-md mx-auto">
            <div class="rounded-2xl bg-white border border-[var(--ui-border)]/40 shadow-sm p-6">
                {{-- Avatar circle --}}
                <div class="mx-auto w-20 h-20 rounded-full bg-amber-100 flex items-center justify-center text-amber-700 text-2xl font-semibold">
                    {{ strtoupper(mb_substr($item->sender_label ?? $item->sender_identifier ?? '?', 0, 1)) }}
                </div>
                {{-- Identity --}}
                <div class="text-center mt-3 mb-5">
                    <div class="text-[15px] font-semibold text-[var(--ui-primary)]">
                        {{ $item->sender_label ?: $item->sender_identifier ?: 'Unbekannt' }}
                    </div>
                    @if($item->sender_identifier && $item->sender_label && $item->sender_identifier !== $item->sender_label)
                        <div class="text-[11px] text-[var(--ui-muted)] tabular-nums mt-0.5">
                            {{ $item->sender_identifier }}
                        </div>
                    @endif
                    <div class="text-[10px] text-[var(--ui-muted)] mt-1">
                        @if($direction === 'inbound')
                            @if($isMissed)
                                <span class="text-amber-600">↺ verpasst</span>
                            @else
                                ↘ eingehend
                            @endif
                        @else
                            ↗ ausgehend
                        @endif
                        ·
                        @if($item->received_at)
                            {{ $item->received_at->format('d.m.Y · H:i') }}
                        @endif
                        @if($item->audio_duration_seconds)
                            · {{ gmdate('i:s', $item->audio_duration_seconds) }}
                        @endif
                    </div>
                </div>
                {{-- Actions --}}
                <div class="flex gap-2 justify-center">
                    <button
                        disabled
                        class="flex-1 px-3 py-2 rounded-full bg-emerald-50 text-emerald-700 text-[12px] font-medium hover:bg-emerald-100 transition flex items-center justify-center gap-1.5 cursor-not-allowed opacity-60"
                        title="Rückruf — kommt in Layer (h)"
                    >
                        @svg('heroicon-o-phone-arrow-up-right', 'w-4 h-4')
                        <span>Rückruf</span>
                    </button>
                    <button
                        disabled
                        class="flex-1 px-3 py-2 rounded-full bg-indigo-50 text-indigo-700 text-[12px] font-medium hover:bg-indigo-100 transition flex items-center justify-center gap-1.5 cursor-not-allowed opacity-60"
                        title="SMS — kommt in Layer (h)"
                    >
                        @svg('heroicon-o-chat-bubble-bottom-center-text', 'w-4 h-4')
                        <span>SMS</span>
                    </button>
                </div>
            </div>

            {{-- Enrichment (transcripts of call recordings) --}}
            @if($enrichment && ($enrichment['tldr'] || $enrichment['summary']))
                <div class="mt-4 rounded-lg border border-[var(--ui-primary)]/30 bg-[var(--ui-primary)]/5 p-4">
                    <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-primary)] mb-1">TLDR</div>
                    <p class="text-[12px] text-[var(--ui-secondary)] m-0 leading-relaxed">
                        {{ $enrichment['tldr'] ?: $enrichment['summary'] }}
                    </p>
                </div>
            @endif

            @if($item->body)
                <div class="mt-4 rounded-lg border border-[var(--ui-border)]/40 bg-white p-4 text-[12px] text-[var(--ui-secondary)] leading-relaxed">
                    <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-1">Notizen</div>
                    <p class="m-0 whitespace-pre-wrap">{{ $item->body }}</p>
                </div>
            @endif
        </div>
    </div>
</div>
