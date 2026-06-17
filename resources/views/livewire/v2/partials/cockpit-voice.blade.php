@php
    /** @var array $data */
    $item = $data['item'];
    $enrichment = $data['enrichment'] ?? null;
    $enrichmentStatus = $data['enrichment_status'] ?? null;
    $enrichmentError = $data['enrichment_error'] ?? null;
    $linked = $data['linked_entities'] ?? [];
    $status = $item->status?->value;
@endphp

<div class="h-full flex flex-col">

    {{-- STICKY HEADER -------------------------------------------- --}}
    <div class="shrink-0 bg-white border-b border-[var(--ui-border)]/40">
        <div class="px-6 py-4">
            <div class="flex items-start justify-between gap-3 mb-2">
                <h2 class="text-[16px] font-semibold text-[var(--ui-primary)] m-0 leading-snug">
                    @svg('heroicon-o-microphone', 'w-4 h-4 inline mr-1 -mt-px text-violet-500')
                    {{ $item->subject ?: 'Sprachnotiz' }}
                </h2>
                <div class="shrink-0 text-[10px] text-[var(--ui-muted)] tabular-nums">
                    @if($item->audio_duration_seconds)
                        {{ gmdate('i:s', $item->audio_duration_seconds) }}
                    @endif
                    @if($item->received_at)
                        · {{ $item->received_at->diffForHumans() }}
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2 text-[11px] text-[var(--ui-secondary)] flex-wrap">
                @if($item->sender_label)
                    <span>{{ $item->sender_label }}</span>
                @endif
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
            <button wire:click="markDone" class="px-2.5 py-1 rounded text-[11px] font-medium text-emerald-700 hover:bg-emerald-50 transition flex items-center gap-1">
                @svg('heroicon-o-check-circle', 'w-3.5 h-3.5')<span>Done</span><kbd class="text-[9px] opacity-60 ml-1">d</kbd>
            </button>
            <button wire:click="toggleSnoozePicker" class="px-2.5 py-1 rounded text-[11px] font-medium text-amber-700 hover:bg-amber-50 transition flex items-center gap-1">
                @svg('heroicon-o-moon', 'w-3.5 h-3.5')<span>Snooze</span><kbd class="text-[9px] opacity-60 ml-1">s</kbd>
            </button>
        </div>
    </div>

    {{-- PLAYER + TRANSCRIPT ------------------------------------- --}}
    <div class="flex-1 overflow-y-auto bg-[var(--ui-muted-5)]/20 p-6">
        <div class="max-w-2xl mx-auto space-y-4">

            {{-- Player placeholder --}}
            <div class="rounded-2xl bg-white border border-[var(--ui-border)]/40 shadow-sm p-5 text-center">
                @svg('heroicon-o-speaker-wave', 'w-10 h-10 mx-auto text-violet-400 mb-2')
                <div class="text-[12px] text-[var(--ui-muted)]">
                    Audio-Player kommt in Layer (i)
                </div>
                @if($item->audio_duration_seconds)
                    <div class="text-[11px] text-[var(--ui-secondary)] mt-1 tabular-nums">
                        Dauer: {{ gmdate('i:s', $item->audio_duration_seconds) }}
                    </div>
                @endif
            </div>

            {{-- TLDR & chips --}}
            @if($enrichment && ($enrichment['tldr'] || $enrichment['headline']))
                <div class="rounded-lg border border-[var(--ui-primary)]/30 bg-[var(--ui-primary)]/5 p-4">
                    @if($enrichment['headline'])
                        <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-primary)] mb-0.5">Headline</div>
                        <p class="text-[13px] font-medium text-[var(--ui-primary)] m-0 mb-2 leading-snug">{{ $enrichment['headline'] }}</p>
                    @endif
                    @if($enrichment['tldr'])
                        <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-primary)] mb-0.5">TLDR</div>
                        <p class="text-[12px] text-[var(--ui-secondary)] m-0 leading-relaxed">{{ $enrichment['tldr'] }}</p>
                    @endif
                </div>
            @else
                @include('inbox::livewire.v2.partials.enrichment-skeleton', [
                    'enrichmentStatus' => $enrichmentStatus,
                    'enrichmentError' => $enrichmentError,
                ])
            @endif

            {{-- Action items (CHIPS) --}}
            @if($enrichment && !empty($enrichment['action_items']))
                <div>
                    <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Highlights</div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($enrichment['action_items'] as $a)
                            @php $text = is_array($a) ? ($a['title'] ?? $a['text'] ?? json_encode($a)) : $a; @endphp
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white border border-violet-200 text-[11px] text-violet-700">
                                {{ $text }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Transcript body --}}
            @if($item->body)
                <div class="rounded-lg border border-[var(--ui-border)]/40 bg-white p-4">
                    <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-1.5">Transkript</div>
                    <pre class="m-0 whitespace-pre-wrap font-sans text-[12px] text-[var(--ui-secondary)] leading-relaxed">{{ $item->body }}</pre>
                </div>
            @endif
        </div>
    </div>
</div>
