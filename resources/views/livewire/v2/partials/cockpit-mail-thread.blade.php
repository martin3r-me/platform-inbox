@php
    /** @var array $data */
    $item = $data['item'];
    $enrichment = $data['enrichment'] ?? null;
    $participants = $data['participants'] ?? [];
    $linked = $data['linked_entities'] ?? [];
    $thread = $data['thread_history']['messages'] ?? [];
    $isMail = $item->channel?->value === 'mail';
    $status = $item->status?->value;
@endphp

<div class="h-full flex flex-col">

    {{-- ==========================================================
         STICKY HEADER
         ========================================================== --}}
    <div class="shrink-0 bg-white border-b border-[var(--ui-border)]/40">
        <div class="px-6 py-4">
            <div class="flex items-start justify-between gap-3 mb-2">
                <h2 class="text-[16px] font-semibold text-[var(--ui-primary)] m-0 leading-snug">
                    @svg('heroicon-o-envelope', 'w-4 h-4 inline mr-1 -mt-px text-slate-500')
                    {{ $item->subject ?: '(ohne Betreff)' }}
                </h2>
                <div class="shrink-0 text-[10px] text-[var(--ui-muted)] tabular-nums">
                    @if($item->received_at)
                        <time datetime="{{ $item->received_at->toIso8601String() }}" title="{{ $item->received_at->format('d.m.Y H:i') }}">
                            {{ $item->received_at->diffForHumans() }}
                        </time>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2 text-[11px] text-[var(--ui-secondary)] flex-wrap">
                <span class="font-medium">{{ $item->sender_label ?: $item->sender_identifier }}</span>
                @if($item->sender_label && $item->sender_identifier)
                    <span class="text-[var(--ui-muted)]">&lt;{{ $item->sender_identifier }}&gt;</span>
                @endif
                @if($status && $status !== 'new')
                    <span class="px-1.5 py-0.5 rounded text-[10px] font-medium
                        {{ $status === 'done' ? 'bg-emerald-50 text-emerald-700' : '' }}
                        {{ $status === 'snoozed' ? 'bg-amber-50 text-amber-700' : '' }}
                        {{ $status === 'ignored' ? 'bg-slate-100 text-slate-600' : '' }}">
                        {{ ucfirst($status) }}
                    </span>
                @endif
                @foreach($linked as $entity)
                    <span class="px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 text-[10px]" title="Verlinkte Entity">
                        🔗 {{ $entity['name'] ?: '#' . $entity['id'] }}
                    </span>
                @endforeach
            </div>
        </div>

        {{-- Action strip ----------------------------------------- --}}
        <div class="flex items-center gap-1 px-6 py-2 border-t border-[var(--ui-border)]/30 bg-[var(--ui-muted-5)]/30">
            <button
                wire:click="markDone"
                class="px-2.5 py-1 rounded text-[11px] font-medium text-emerald-700 hover:bg-emerald-50 transition flex items-center gap-1"
                title="Done — Taste d"
            >
                @svg('heroicon-o-check-circle', 'w-3.5 h-3.5')
                <span>Done</span>
                <kbd class="text-[9px] opacity-60 ml-1">d</kbd>
            </button>
            <button
                wire:click="snooze(4)"
                class="px-2.5 py-1 rounded text-[11px] font-medium text-amber-700 hover:bg-amber-50 transition flex items-center gap-1"
                title="Snooze 4h — Taste s"
            >
                @svg('heroicon-o-moon', 'w-3.5 h-3.5')
                <span>Snooze 4h</span>
                <kbd class="text-[9px] opacity-60 ml-1">s</kbd>
            </button>
            <button
                disabled
                class="px-2.5 py-1 rounded text-[11px] font-medium text-[var(--ui-muted)] cursor-not-allowed flex items-center gap-1 opacity-50"
                title="Handoff — kommt in Layer (i)"
            >
                @svg('heroicon-o-arrow-uturn-right', 'w-3.5 h-3.5')
                <span>Handoff</span>
                <kbd class="text-[9px] opacity-60 ml-1">h</kbd>
            </button>
            <button
                disabled
                class="px-2.5 py-1 rounded text-[11px] font-medium text-[var(--ui-muted)] cursor-not-allowed flex items-center gap-1 opacity-50"
                title="Link Entity — kommt in Layer (i)"
            >
                @svg('heroicon-o-link', 'w-3.5 h-3.5')
                <span>Link</span>
                <kbd class="text-[9px] opacity-60 ml-1">l</kbd>
            </button>
            <div class="flex-1"></div>
            <button
                disabled
                class="px-2.5 py-1 rounded text-[11px] font-medium text-[var(--ui-muted)] cursor-not-allowed flex items-center gap-1 opacity-50"
                title="Reply — kommt in Layer (h)"
            >
                @svg('heroicon-o-arrow-uturn-left', 'w-3.5 h-3.5')
                <span>Reply</span>
                <kbd class="text-[9px] opacity-60 ml-1">r</kbd>
            </button>
        </div>
    </div>

    {{-- ==========================================================
         SCROLL BODY
         ========================================================== --}}
    <div class="flex-1 overflow-y-auto">
        <div class="max-w-3xl mx-auto p-6 space-y-5">

            {{-- TLDR card ------------------------------------------- --}}
            @if($enrichment && ($enrichment['tldr'] || $enrichment['headline']))
                <div class="rounded-lg border border-[var(--ui-primary)]/30 bg-[var(--ui-primary)]/5 p-4">
                    <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-primary)] mb-1">
                        @if($enrichment['headline']) Headline @else TLDR @endif
                    </div>
                    @if($enrichment['headline'])
                        <p class="text-[14px] font-medium text-[var(--ui-primary)] m-0 mb-1 leading-snug">
                            {{ $enrichment['headline'] }}
                        </p>
                    @endif
                    @if($enrichment['tldr'])
                        <p class="text-[12px] text-[var(--ui-secondary)] m-0 leading-relaxed">
                            {{ $enrichment['tldr'] }}
                        </p>
                    @endif
                </div>
            @endif

            {{-- Action items ---------------------------------------- --}}
            @if($enrichment && !empty($enrichment['action_items']))
                <div>
                    <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">
                        Action Items
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($enrichment['action_items'] as $idx => $action)
                            @php
                                $text = is_array($action) ? ($action['title'] ?? $action['text'] ?? json_encode($action)) : $action;
                            @endphp
                            <div
                                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-full bg-white border border-[var(--ui-border)]/50 text-[11px] text-[var(--ui-secondary)]"
                            >
                                <span>{{ $text }}</span>
                                <span class="text-[var(--ui-muted)] opacity-40">·</span>
                                <span class="text-[10px] text-[var(--ui-muted)]">→ Task / Termin (Layer i)</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Original body --------------------------------------- --}}
            <div>
                <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">
                    Original-Inhalt
                </div>
                <div class="rounded-lg border border-[var(--ui-border)]/40 bg-white p-4 text-[12.5px] text-[var(--ui-secondary)] leading-relaxed">
                    @if($item->body)
                        @if($item->body_format === 'html')
                            <div class="prose prose-sm max-w-none">
                                {!! $item->body !!}
                            </div>
                        @else
                            <pre class="whitespace-pre-wrap font-sans m-0">{{ $item->body }}</pre>
                        @endif
                    @elseif($item->preview)
                        <p class="m-0 text-[var(--ui-muted)] italic">{{ $item->preview }}</p>
                    @else
                        <p class="m-0 text-[var(--ui-muted)] italic">(kein Inhalt vorhanden)</p>
                    @endif
                </div>
            </div>

            {{-- Thread history -------------------------------------- --}}
            @if(!empty($thread) && count($thread) > 1)
                <details class="rounded-lg border border-[var(--ui-border)]/40 bg-white">
                    <summary class="cursor-pointer px-4 py-2.5 text-[11px] font-medium text-[var(--ui-secondary)] flex items-center justify-between">
                        <span>
                            @svg('heroicon-o-queue-list', 'w-3.5 h-3.5 inline mr-1')
                            Verlauf ({{ count($thread) }} Nachrichten im Thread)
                        </span>
                        <span class="text-[var(--ui-muted)] text-[10px]">aufklappen ▾</span>
                    </summary>
                    <div class="border-t border-[var(--ui-border)]/30 divide-y divide-[var(--ui-border)]/30">
                        @foreach($thread as $msg)
                            @php $isMine = ($msg['direction'] ?? null) === 'outbound'; @endphp
                            <div class="px-4 py-3 {{ $isMine ? 'bg-blue-50/30' : '' }}">
                                <div class="flex items-center justify-between gap-2 mb-1">
                                    <div class="text-[11px] text-[var(--ui-secondary)]">
                                        <span class="font-medium">
                                            @if($isMine) Du
                                            @else {{ $msg['from_name'] ?? $msg['from_address'] ?? $msg['from'] ?? '?' }}
                                            @endif
                                        </span>
                                        @if(!empty($msg['from_address']) && !$isMine)
                                            <span class="text-[var(--ui-muted)]">&lt;{{ $msg['from_address'] }}&gt;</span>
                                        @endif
                                    </div>
                                    <span class="text-[10px] text-[var(--ui-muted)] tabular-nums">
                                        @if(!empty($msg['received_at']) || !empty($msg['sent_at']))
                                            {{ \Carbon\Carbon::parse($msg['received_at'] ?? $msg['sent_at'])->format('d.m. H:i') }}
                                        @endif
                                    </span>
                                </div>
                                @if(!empty($msg['preview']))
                                    <p class="text-[11.5px] text-[var(--ui-secondary)] leading-snug m-0">
                                        {{ \Illuminate\Support\Str::limit(strip_tags($msg['preview']), 200) }}
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif

            {{-- Participants chip-row ------------------------------ --}}
            @if(!empty($participants))
                <div class="pt-2 border-t border-[var(--ui-border)]/30">
                    <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-1.5">
                        Teilnehmer
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($participants as $p)
                            <span class="px-2 py-0.5 rounded bg-[var(--ui-muted-5)] text-[10.5px] text-[var(--ui-secondary)]">
                                {{ $p['display_name'] ?: $p['identifier'] }}
                                <span class="text-[var(--ui-muted)]">· {{ $p['role'] }}</span>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- ==========================================================
         REPLY BAR (stub bis Layer h)
         ========================================================== --}}
    <div class="shrink-0 border-t border-[var(--ui-border)]/40 bg-[var(--ui-muted-5)]/40 px-6 py-3">
        <div class="flex items-center gap-2 text-[11px] text-[var(--ui-muted)]">
            @svg('heroicon-o-arrow-uturn-left', 'w-3.5 h-3.5')
            <span>Reply-Composer kommt in Layer (h). Bis dahin via</span>
            <a href="{{ route('inbox.items.show', $item) }}" class="underline hover:text-[var(--ui-primary)]">
                V1 Show
            </a>
            <span>.</span>
        </div>
    </div>
</div>
