@php
    $summary = $this->summary;
    $byProvider = $this->byProvider;
    $byTemplate = $this->byTemplate;
    $daily = $this->daily;
    $maxDailyCost = max(1, max(array_column($daily, 'cost')));
    $window = match($range) {
        '90d' => 'Letzte 90 Tage',
        'month' => 'Diesen Monat',
        'last_month' => 'Letzten Monat',
        default => 'Letzte 30 Tage',
    };
    $fmtCents = fn ($mc) => number_format(((int) $mc) / 10000, 4, ',', '.') . ' ¢';
    $fmtMs = fn ($ms) => $ms >= 1000 ? number_format($ms / 1000, 2, ',', '.') . ' s' : (int) round($ms) . ' ms';
@endphp

<x-ui-page x-data="{}">
    <x-slot name="navbar">
        <x-ui-page-navbar title="Kosten" icon="heroicon-o-banknotes" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Inbox', 'href' => route('inbox.index'), 'icon' => 'inbox'],
            ['label' => 'Kosten'],
        ]">
            <div class="inline-flex rounded-md border border-[var(--ui-border)]/60 overflow-hidden">
                @foreach([
                    '30d' => '30 T',
                    '90d' => '90 T',
                    'month' => 'Monat',
                    'last_month' => 'Vormonat',
                ] as $k => $label)
                    <button wire:click="setRange('{{ $k }}')"
                            class="px-2 py-1 text-[11px] transition-colors {{ $range === $k ? 'bg-[var(--ui-primary)] text-white font-medium' : 'text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]' }} {{ !$loop->first ? 'border-l border-[var(--ui-border)]/60' : '' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </x-ui-page-actionbar>
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Übersicht" icon="heroicon-o-information-circle" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-5 bg-[var(--ui-muted-5)]">
                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Was ist das?</h3>
                    <p class="text-[11px] text-[var(--ui-secondary)] leading-relaxed m-0">
                        Aufschlüsselung der Kosten je Enrichment-Lauf — nach Provider und Template. Basis für A/B-Vergleiche (OpenAI vs. Claude) und für die Frage „welches Template ist der Kostentreiber?". Kosten in Cent (1¢ = 0,01€), provider-eigene Preise vorausgesetzt.
                    </p>
                </section>
                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Zeitraum</h3>
                    <p class="text-[11px] text-[var(--ui-secondary)] m-0"><strong>{{ $window }}</strong></p>
                </section>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 min-w-0 min-h-0 flex flex-col overflow-auto p-6 space-y-6">

        {{-- KPI Cards --}}
        <div class="grid grid-cols-4 gap-3">
            <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-4">
                <div class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">Kosten</div>
                <div class="text-xl font-semibold text-[var(--ui-secondary)] tabular-nums mt-1">{{ $fmtCents($summary['cost']) }}</div>
                <div class="text-[10px] text-[var(--ui-muted)] mt-1">≈ {{ number_format($summary['cost'] / 1_000_000, 4, ',', '.') }} €</div>
            </div>
            <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-4">
                <div class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">Läufe</div>
                <div class="text-xl font-semibold text-[var(--ui-secondary)] tabular-nums mt-1">{{ number_format($summary['runs'], 0, ',', '.') }}</div>
                <div class="text-[10px] text-[var(--ui-muted)] mt-1">Ø {{ $fmtCents($summary['avg_cost_per_run']) }} / Lauf</div>
            </div>
            <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-4">
                <div class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">Tokens (in / out)</div>
                <div class="text-xl font-semibold text-[var(--ui-secondary)] tabular-nums mt-1">
                    {{ number_format($summary['tokens_in'], 0, ',', '.') }}
                    <span class="text-[var(--ui-muted)] text-sm font-normal">/</span>
                    {{ number_format($summary['tokens_out'], 0, ',', '.') }}
                </div>
                <div class="text-[10px] text-[var(--ui-muted)] mt-1">Σ {{ number_format($summary['tokens_in'] + $summary['tokens_out'], 0, ',', '.') }}</div>
            </div>
            <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-4">
                <div class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">Fail-Rate</div>
                <div class="text-xl font-semibold tabular-nums mt-1 {{ $summary['fail_rate'] > 0.1 ? 'text-red-600' : 'text-[var(--ui-secondary)]' }}">
                    {{ number_format($summary['fail_rate'] * 100, 1, ',', '.') }} %
                </div>
                <div class="text-[10px] text-[var(--ui-muted)] mt-1">{{ $summary['failures'] }} von {{ $summary['runs'] }} · Ø {{ $fmtMs($summary['avg_duration_ms']) }}</div>
            </div>
        </div>

        {{-- Daily timeline --}}
        @if(!empty($daily))
            <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-4">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold text-[var(--ui-secondary)] m-0">Kostenverlauf</h2>
                    <span class="text-[10px] text-[var(--ui-muted)]">{{ count($daily) }} Tage</span>
                </div>
                <div class="flex items-end gap-[2px] h-24">
                    @foreach($daily as $d)
                        @php
                            $h = max(2, (int) round(($d['cost'] / $maxDailyCost) * 96));
                            $tooltip = $d['day'] . ' · ' . $fmtCents($d['cost']) . ' · ' . $d['runs'] . ' Läufe';
                        @endphp
                        <div title="{{ $tooltip }}"
                             class="flex-1 bg-[var(--ui-primary)]/60 hover:bg-[var(--ui-primary)] rounded-t-sm transition-colors"
                             style="height: {{ $h }}px;"></div>
                    @endforeach
                </div>
                <div class="flex justify-between text-[10px] text-[var(--ui-muted)] mt-1">
                    <span>{{ \Carbon\Carbon::parse($daily[0]['day'])->format('d.m.') }}</span>
                    <span>{{ \Carbon\Carbon::parse(end($daily)['day'])->format('d.m.') }}</span>
                </div>
            </div>
        @endif

        {{-- Provider breakdown --}}
        <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg overflow-hidden">
            <div class="px-4 py-3 border-b border-[var(--ui-border)]/40 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-[var(--ui-secondary)] m-0">Pro Provider</h2>
                <span class="text-[10px] text-[var(--ui-muted)]">sortiert nach Gesamtkosten</span>
            </div>
            @if(empty($byProvider))
                <p class="px-4 py-6 text-[12px] text-[var(--ui-muted)] italic m-0">Keine Daten im gewählten Zeitraum.</p>
            @else
                <table class="w-full text-[12px]">
                    <thead class="bg-[var(--ui-muted-5)] text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">
                        <tr>
                            <th class="text-left px-3 py-2">Provider</th>
                            <th class="text-right px-3 py-2">Läufe</th>
                            <th class="text-right px-3 py-2">Tokens in / out</th>
                            <th class="text-right px-3 py-2">Σ Kosten</th>
                            <th class="text-right px-3 py-2">Ø / Lauf</th>
                            <th class="text-right px-3 py-2">Ø Dauer</th>
                            <th class="text-right px-3 py-2">Fails</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/40">
                        @foreach($byProvider as $p)
                            <tr class="hover:bg-[var(--ui-muted-5)]/50">
                                <td class="px-3 py-2 font-mono text-[11px] text-[var(--ui-secondary)]">{{ $p['provider'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($p['runs'], 0, ',', '.') }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-[var(--ui-muted)]">
                                    {{ number_format($p['tokens_in'], 0, ',', '.') }} / {{ number_format($p['tokens_out'], 0, ',', '.') }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums font-medium">{{ $fmtCents($p['cost']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-[var(--ui-muted)]">{{ $fmtCents($p['avg_cost']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-[var(--ui-muted)]">{{ $fmtMs($p['avg_duration_ms']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums {{ $p['failures'] > 0 ? 'text-red-600' : 'text-[var(--ui-muted)]' }}">{{ $p['failures'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Template breakdown --}}
        <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg overflow-hidden">
            <div class="px-4 py-3 border-b border-[var(--ui-border)]/40 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-[var(--ui-secondary)] m-0">Pro Template × Provider</h2>
                <span class="text-[10px] text-[var(--ui-muted)]">A/B-Sicht — gleicher Template-Key, verschiedene Provider</span>
            </div>
            @if(empty($byTemplate))
                <p class="px-4 py-6 text-[12px] text-[var(--ui-muted)] italic m-0">Keine Daten im gewählten Zeitraum.</p>
            @else
                <table class="w-full text-[12px]">
                    <thead class="bg-[var(--ui-muted-5)] text-[10px] uppercase tracking-wider text-[var(--ui-muted)]">
                        <tr>
                            <th class="text-left px-3 py-2">Template</th>
                            <th class="text-left px-3 py-2">Provider</th>
                            <th class="text-right px-3 py-2">Läufe</th>
                            <th class="text-right px-3 py-2">Σ Kosten</th>
                            <th class="text-right px-3 py-2">Ø / Lauf</th>
                            <th class="text-right px-3 py-2">Ø Dauer</th>
                            <th class="text-right px-3 py-2">Fails</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--ui-border)]/40">
                        @foreach($byTemplate as $t)
                            <tr class="hover:bg-[var(--ui-muted-5)]/50">
                                <td class="px-3 py-2">
                                    <span class="font-mono text-[11px] text-[var(--ui-secondary)]">{{ $t['template_key'] }}</span>
                                    @if($t['max_version'])<span class="text-[10px] text-[var(--ui-muted)] ml-1">v{{ $t['max_version'] }}</span>@endif
                                </td>
                                <td class="px-3 py-2 font-mono text-[11px] text-[var(--ui-muted)]">{{ $t['provider'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($t['runs'], 0, ',', '.') }}</td>
                                <td class="px-3 py-2 text-right tabular-nums font-medium">{{ $fmtCents($t['cost']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-[var(--ui-muted)]">{{ $fmtCents($t['avg_cost']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-[var(--ui-muted)]">{{ $fmtMs($t['avg_duration_ms']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums {{ $t['failures'] > 0 ? 'text-red-600' : 'text-[var(--ui-muted)]' }}">{{ $t['failures'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

    </div>
</x-ui-page>
