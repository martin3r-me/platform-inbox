@php
    $buckets = $this->bucketDefs;
    $counts = $this->bucketCounts;
    $stream = $this->stream;
    $cockpitMode = $this->cockpitMode;
@endphp

<x-ui-page
    x-data="inboxV2Keymap()"
    x-init="bind($wire)"
    @keydown.window="onKey($event)"
>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Inbox" icon="heroicon-o-inbox">
            <div class="flex items-center gap-2 text-[11px] text-[var(--ui-muted)]">
                <span>Sort:</span>
                <button
                    wire:click="toggleSort"
                    class="px-2 py-0.5 rounded border border-[var(--ui-border)]/60 hover:bg-[var(--ui-muted-5)] transition"
                    title="Shift+S"
                >
                    @if($sortMode === 'smart')
                        ⚡ Smart
                    @else
                        🕒 Chronologisch
                    @endif
                </button>
                <span class="ml-3 opacity-60">? = Tastatur-Hilfe</span>
            </div>
        </x-ui-page-navbar>
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Buckets" icon="heroicon-o-funnel" width="w-60" :defaultOpen="true">
            <div class="p-3 space-y-1">
                @foreach($buckets as $def)
                    @php
                        $isActive = $bucket === $def['key'];
                        $count = $counts[$def['key']] ?? 0;
                        $isArchive = $def['group'] === 'archive';
                    @endphp
                    @if($isArchive && $loop->index > 0 && $buckets[$loop->index - 1]['group'] !== 'archive')
                        <div class="border-t border-[var(--ui-border)]/30 my-2"></div>
                    @endif
                    <button
                        wire:click="setBucket('{{ $def['key'] }}')"
                        class="w-full flex items-center justify-between gap-2 px-2.5 py-2 rounded-md text-left text-[12px] transition
                            {{ $isActive
                                ? 'bg-[var(--ui-primary)]/10 text-[var(--ui-primary)] font-medium ring-1 ring-[var(--ui-primary)]/30'
                                : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}"
                    >
                        <span class="flex items-center gap-2 truncate">
                            @svg('heroicon-o-' . $def['icon'], 'w-3.5 h-3.5 shrink-0')
                            <span class="truncate">{{ $def['label'] }}</span>
                        </span>
                        @if($count > 0)
                            <span class="text-[10px] tabular-nums opacity-70">{{ $count }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="border-t border-[var(--ui-border)]/30 mt-2 p-3">
                <h4 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Filter</h4>
                <div class="space-y-1.5">
                    @foreach([
                        null => 'Alle Kanäle',
                        'mail' => 'E-Mail',
                        'message' => 'Teams / Chat',
                        'call' => 'Anrufe',
                        'meeting' => 'Meetings',
                    ] as $ch => $lbl)
                        <button
                            wire:click="$set('filterChannel', @js($ch))"
                            class="w-full text-left px-2 py-1 rounded text-[11px] transition
                                {{ $filterChannel === $ch
                                    ? 'bg-[var(--ui-muted-5)] text-[var(--ui-primary)] font-medium'
                                    : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}"
                        >
                            {{ $lbl }}
                        </button>
                    @endforeach
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- ============================================================
         Content: Stream (left) + Cockpit (right)
         ============================================================ --}}
    <div class="flex h-[calc(100vh-3.5rem)] bg-[var(--ui-muted-5)]/30">

        {{-- STREAM ------------------------------------------------- --}}
        <div class="w-[440px] shrink-0 border-r border-[var(--ui-border)]/40 overflow-y-auto" id="inbox-v2-stream">
            @if(empty($stream))
                <div class="p-8 text-center text-[12px] text-[var(--ui-muted)]">
                    Keine Items in diesem Bucket.
                </div>
            @else
                <div class="p-3 space-y-2">
                    @foreach($stream as $row)
                        @include('inbox::livewire.v2.partials.sender-card', ['row' => $row])
                    @endforeach
                </div>
            @endif
        </div>

        {{-- COCKPIT ------------------------------------------------ --}}
        <div class="flex-1 overflow-y-auto bg-white" id="inbox-v2-cockpit">
            @if($cockpitMode === 'empty')
                @include('inbox::livewire.v2.partials.cockpit-empty')
            @elseif($cockpitMode === 'sender-overview')
                @include('inbox::livewire.v2.partials.cockpit-sender-overview', ['stream' => $stream])
            @elseif($cockpitMode === 'thread')
                @include('inbox::livewire.v2.partials.cockpit-thread', ['stream' => $stream])
            @endif
        </div>
    </div>

    @push('scripts')
        <script>
            window.inboxV2Keymap = function () {
                return {
                    wire: null,
                    helpOpen: false,
                    bind(wire) { this.wire = wire; },
                    isTyping(target) {
                        if (!target) return false;
                        const tag = (target.tagName || '').toLowerCase();
                        if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
                        return target.isContentEditable === true;
                    },
                    onKey(e) {
                        if (!this.wire) return;
                        if (this.isTyping(e.target)) {
                            if (e.key === 'Escape') e.target.blur();
                            return;
                        }
                        // Shift+S — sort toggle (must run before lower-case switch)
                        if (e.shiftKey && (e.key === 'S' || e.key === 's')) {
                            e.preventDefault();
                            this.wire.call('toggleSort');
                            return;
                        }
                        switch (e.key) {
                            case 'j': e.preventDefault(); this.wire.call('moveSender', 1); break;
                            case 'k': e.preventDefault(); this.wire.call('moveSender', -1); break;
                            case 'J': e.preventDefault(); this.wire.call('moveThread', 1); break;
                            case 'K': e.preventDefault(); this.wire.call('moveThread', -1); break;
                            case 'Enter': e.preventDefault(); this.wire.call('moveThread', 1); break;
                            case 'Escape': e.preventDefault(); this.wire.call('clearSelection'); break;
                            case 'e':
                                e.preventDefault();
                                if (this.wire.senderKey) this.wire.call('toggleExpand', this.wire.senderKey);
                                break;
                            case '?': e.preventDefault(); this.helpOpen = !this.helpOpen; break;
                            // d/s/h/l/r/c land here in layer (i) — verbs need a
                            // selection and channel-aware behaviour, stubbed until then.
                            default: break;
                        }
                    },
                };
            };
        </script>
    @endpush
</x-ui-page>
