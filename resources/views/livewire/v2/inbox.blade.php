@php
    $buckets = $this->bucketDefs;
    $counts = $this->bucketCounts;
    $stream = $this->streamRows;
    $streamFlat = $this->streamItems;
    $itemCounts = $streamFlat['counts'] ?? [];
    $itemGroups = $streamFlat['groups'] ?? [];
    $cockpitMode = $this->cockpitMode;
@endphp

<x-ui-page
    x-data="inboxV2Keymap()"
    x-init="bind($wire)"
    @keydown.window="onKey($event)"
>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Inbox" icon="heroicon-o-inbox">
            <div class="flex items-center gap-3 text-[11px] text-[var(--ui-muted)]">
                @php
                    $activeBucketDef = collect($buckets)->firstWhere('key', $bucket);
                    $activeCount = $counts[$bucket] ?? 0;
                @endphp
                @if($activeBucketDef)
                    <span class="text-[var(--ui-secondary)]">
                        <span class="font-medium">{{ $activeBucketDef['label'] }}</span>
                        <span class="tabular-nums opacity-70">· {{ $activeCount }}</span>
                    </span>
                    <span class="opacity-30">|</span>
                @endif
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
                <button
                    wire:click="toggleHelp"
                    class="px-2 py-0.5 rounded border border-[var(--ui-border)]/60 hover:bg-[var(--ui-muted-5)] transition flex items-center gap-1"
                    title="Tastatur-Hilfe (?)"
                >
                    <span>?</span>
                </button>
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
        <div class="w-[440px] shrink-0 border-r border-[var(--ui-border)]/40 overflow-y-auto flex flex-col" id="inbox-v2-stream">

            {{-- Quick-Filter-Bar: Alle / Heute / Awaiting / Meetings ----
                 Live-Counters aus streamItems['counts'] — bleibt sticky beim
                 Scrollen, damit der Filter immer erreichbar ist. --}}
            <div class="sticky top-0 z-10 bg-[var(--ui-muted-5)]/95 backdrop-blur border-b border-[var(--ui-border)]/40 px-3 py-2 flex items-center gap-1 text-[11px]">
                @php
                    $chips = [
                        ['key' => null,        'label' => 'Alle',     'count' => $itemCounts['total']    ?? 0],
                        ['key' => 'today',     'label' => 'Heute',    'count' => $itemCounts['today']    ?? 0],
                        ['key' => 'awaiting',  'label' => 'Awaiting', 'count' => $itemCounts['awaiting'] ?? 0],
                        ['key' => 'meeting',   'label' => 'Meetings', 'count' => $itemCounts['meeting']  ?? 0],
                    ];
                @endphp
                @foreach($chips as $chip)
                    @php $active = ($quickFilter ?? null) === $chip['key']; @endphp
                    <button
                        type="button"
                        wire:click="setQuickFilter(@js($chip['key']))"
                        class="px-2 py-1 rounded-md transition flex items-center gap-1
                            {{ $active
                                ? 'bg-[var(--ui-primary)] text-white font-medium'
                                : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }}"
                    >
                        <span>{{ $chip['label'] }}</span>
                        @if($chip['count'] > 0)
                            <span class="text-[10px] tabular-nums {{ $active ? 'opacity-90' : 'opacity-60' }}">
                                {{ $chip['count'] }}
                            </span>
                        @endif
                    </button>
                @endforeach
            </div>

            {{-- Stream-Body: flache Item-Liste, gruppiert nach Datum ---- --}}
            <div class="flex-1 overflow-y-auto">
                @if(empty($itemGroups))
                    <div class="p-8 text-center text-[12px] text-[var(--ui-muted)]">
                        Keine Items in dieser Auswahl.
                    </div>
                @else
                    @foreach($itemGroups as $group)
                        <div class="px-3 pt-3 pb-1 flex items-center gap-2 text-[10px] uppercase tracking-wider text-[var(--ui-muted)] font-semibold">
                            <span>{{ $group['label'] }}</span>
                            <span class="opacity-50 tabular-nums">{{ count($group['items']) }}</span>
                            <span class="flex-1 h-px bg-[var(--ui-border)]/30"></span>
                        </div>
                        <div class="px-2 pb-2 space-y-0.5">
                            @foreach($group['items'] as $item)
                                @include('inbox::livewire.v2.partials.stream-item', [
                                    'item' => $item,
                                    'bucketKey' => $group['key'],
                                ])
                            @endforeach
                        </div>
                    @endforeach
                @endif
            </div>
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

    {{-- Overlays --}}
    @include('inbox::livewire.v2.partials.keyboard-help')
    @include('inbox::livewire.v2.partials.snooze-picker')

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
                            // j/k stepped through senders in the old folded
                            // stream; with the flat date-grouped list the
                            // natural unit is the item. Shift+J/K stays as
                            // an escape hatch for sender-level jumps.
                            case 'j': e.preventDefault(); this.wire.call('moveItem', 1); break;
                            case 'k': e.preventDefault(); this.wire.call('moveItem', -1); break;
                            case 'J': e.preventDefault(); this.wire.call('moveSender', 1); break;
                            case 'K': e.preventDefault(); this.wire.call('moveSender', -1); break;
                            case 'Enter': e.preventDefault(); this.wire.call('moveItem', 1); break;
                            case 'Escape': e.preventDefault(); this.wire.call('clearSelection'); break;
                            case 'e':
                                e.preventDefault();
                                if (this.wire.senderKey) this.wire.call('toggleExpand', this.wire.senderKey);
                                break;
                            case '?': e.preventDefault(); this.wire.call('toggleHelp'); break;
                            // Verbs — only when a thread is selected, otherwise no-op
                            // so a stray press on the sender list doesn't mark random items.
                            case 'd':
                                if (this.wire.threadKey) {
                                    e.preventDefault();
                                    this.wire.call('markDone');
                                }
                                break;
                            case 's':
                                if (this.wire.threadKey) {
                                    e.preventDefault();
                                    this.wire.call('toggleSnoozePicker');
                                }
                                break;
                            case 'r':
                                if (this.wire.threadKey) {
                                    e.preventDefault();
                                    this.wire.call('openReply');
                                }
                                break;
                            // h/l/c remain stubs — they need entity/contact pickers
                            // that will hook into organization + planner modules.
                            default: break;
                        }
                    },
                };
            };
        </script>
    @endpush
</x-ui-page>
