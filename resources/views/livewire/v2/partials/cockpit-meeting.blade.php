@php
    /** @var array $data */
    $item = $data['item'];
    $enrichment = $data['enrichment'] ?? null;
    $enrichmentStatus = $data['enrichment_status'] ?? null;
    $enrichmentError = $data['enrichment_error'] ?? null;
    $participants = $data['participants'] ?? [];
    $linked = $data['linked_entities'] ?? [];
    $status = $item->status?->value;
@endphp

<div class="h-full flex flex-col">

    {{-- STICKY HEADER -------------------------------------------- --}}
    <div class="shrink-0 bg-white border-b border-[var(--ui-border)]/40">
        <div class="px-6 py-4">
            <div class="flex items-start justify-between gap-3 mb-2">
                <h2 class="text-[16px] font-semibold text-[var(--ui-primary)] m-0 leading-snug">
                    @svg('heroicon-o-calendar-days', 'w-4 h-4 inline mr-1 -mt-px text-emerald-500')
                    {{ $item->subject ?: '(Meeting ohne Titel)' }}
                </h2>
                <div class="shrink-0 text-[10px] text-[var(--ui-muted)] tabular-nums">
                    @if($item->received_at)
                        <time datetime="{{ $item->received_at->toIso8601String() }}">{{ $item->received_at->format('d.m.Y · H:i') }}</time>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2 text-[11px] text-[var(--ui-secondary)] flex-wrap">
                @if($item->sender_label)
                    <span>Organisator: <span class="font-medium">{{ $item->sender_label }}</span></span>
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
            <button disabled class="px-2.5 py-1 rounded text-[11px] text-[var(--ui-muted)] cursor-not-allowed flex items-center gap-1 opacity-50">
                @svg('heroicon-o-link', 'w-3.5 h-3.5')<span>Link</span><kbd class="text-[9px] opacity-60 ml-1">l</kbd>
            </button>
        </div>
    </div>

    {{-- EVENT CARD ---------------------------------------------- --}}
    <div class="flex-1 overflow-y-auto bg-[var(--ui-muted-5)]/20 p-6">
        <div class="max-w-2xl mx-auto space-y-4">

            <div class="rounded-2xl bg-white border border-[var(--ui-border)]/40 shadow-sm p-5">
                <div class="flex items-start gap-4">
                    {{-- Date block --}}
                    <div class="shrink-0 w-16 text-center">
                        @if($item->received_at)
                            <div class="text-[10px] uppercase font-semibold text-emerald-700 tracking-wider">
                                {{ $item->received_at->locale('de')->isoFormat('MMM') }}
                            </div>
                            <div class="text-[28px] font-semibold text-[var(--ui-primary)] leading-none tabular-nums">
                                {{ $item->received_at->format('d') }}
                            </div>
                            <div class="text-[10px] text-[var(--ui-muted)] tabular-nums mt-0.5">
                                {{ $item->received_at->format('H:i') }}
                            </div>
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-[14px] font-medium text-[var(--ui-primary)] leading-snug">
                            {{ $item->subject ?: '(Meeting)' }}
                        </div>
                        @if($item->preview)
                            <p class="text-[11.5px] text-[var(--ui-secondary)] leading-relaxed m-0 mt-1">
                                {{ \Illuminate\Support\Str::limit(strip_tags($item->preview), 200) }}
                            </p>
                        @endif
                    </div>
                </div>

                {{-- RSVP --}}
                <div class="flex gap-2 mt-5 pt-4 border-t border-[var(--ui-border)]/30">
                    <button disabled class="flex-1 px-3 py-2 rounded-full bg-emerald-50 text-emerald-700 text-[11.5px] font-medium hover:bg-emerald-100 transition flex items-center justify-center gap-1.5 cursor-not-allowed opacity-60" title="kommt in Layer (h)">
                        @svg('heroicon-o-check', 'w-4 h-4')<span>Annehmen</span>
                    </button>
                    <button disabled class="flex-1 px-3 py-2 rounded-full bg-amber-50 text-amber-700 text-[11.5px] font-medium hover:bg-amber-100 transition flex items-center justify-center gap-1.5 cursor-not-allowed opacity-60" title="kommt in Layer (h)">
                        @svg('heroicon-o-question-mark-circle', 'w-4 h-4')<span>Vielleicht</span>
                    </button>
                    <button disabled class="flex-1 px-3 py-2 rounded-full bg-rose-50 text-rose-700 text-[11.5px] font-medium hover:bg-rose-100 transition flex items-center justify-center gap-1.5 cursor-not-allowed opacity-60" title="kommt in Layer (h)">
                        @svg('heroicon-o-x-mark', 'w-4 h-4')<span>Ablehnen</span>
                    </button>
                </div>
            </div>

            {{-- Attendees --}}
            @if(!empty($participants))
                <div class="rounded-lg border border-[var(--ui-border)]/40 bg-white p-4">
                    <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">
                        Teilnehmer ({{ count($participants) }})
                    </div>
                    <div class="space-y-1.5">
                        @foreach($participants as $p)
                            <div class="flex items-center gap-2 text-[12px] text-[var(--ui-secondary)]">
                                <span class="w-1.5 h-1.5 rounded-full bg-[var(--ui-muted)] shrink-0"></span>
                                <span class="font-medium">{{ $p['display_name'] ?: $p['identifier'] }}</span>
                                <span class="text-[10px] text-[var(--ui-muted)]">· {{ $p['role'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Enrichment --}}
            @if($enrichment && $enrichment['tldr'])
                <div class="rounded-lg border border-[var(--ui-primary)]/30 bg-[var(--ui-primary)]/5 p-4">
                    <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-primary)] mb-1">TLDR</div>
                    <p class="text-[12px] text-[var(--ui-secondary)] m-0 leading-relaxed">{{ $enrichment['tldr'] }}</p>
                </div>
            @else
                @include('inbox::livewire.v2.partials.enrichment-skeleton', [
                    'enrichmentStatus' => $enrichmentStatus,
                    'enrichmentError' => $enrichmentError,
                ])
            @endif
        </div>
    </div>
</div>
