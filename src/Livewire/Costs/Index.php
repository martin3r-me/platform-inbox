<?php

namespace Platform\Inbox\Livewire\Costs;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Enrichment cost dashboard — answers "what do our LLM runs actually cost,
 * and which provider/template is the lever?" Team-scoped via inbox_items.
 *
 * cost_micro_cents = 1/10000 of a cent. We display in cents with 4 decimals
 * to stay precise for fractional/sub-cent provider pricing.
 */
class Index extends Component
{
    /** "30d" | "90d" | "month" | "last_month" */
    public string $range = '30d';

    protected function teamId(): int
    {
        return auth()->user()->currentTeam->id;
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, label: string} */
    protected function window(): array
    {
        $now = CarbonImmutable::now();
        return match ($this->range) {
            '90d' => ['from' => $now->subDays(90)->startOfDay(), 'to' => $now, 'label' => 'Letzte 90 Tage'],
            'month' => ['from' => $now->startOfMonth(), 'to' => $now, 'label' => 'Diesen Monat'],
            'last_month' => [
                'from' => $now->subMonthNoOverflow()->startOfMonth(),
                'to' => $now->subMonthNoOverflow()->endOfMonth(),
                'label' => 'Letzten Monat',
            ],
            default => ['from' => $now->subDays(30)->startOfDay(), 'to' => $now, 'label' => 'Letzte 30 Tage'],
        };
    }

    /**
     * Base query: enrichments joined with their inbox_item to apply team scope.
     */
    protected function baseQuery()
    {
        $w = $this->window();
        return DB::table('inbox_item_enrichments as e')
            ->join('inbox_items as i', 'i.id', '=', 'e.inbox_item_id')
            ->where('i.team_id', $this->teamId())
            ->whereBetween('e.created_at', [$w['from'], $w['to']]);
    }

    /**
     * Top-of-page KPIs: total cost, total runs, total tokens, fail-rate.
     * @return array{cost: int, runs: int, tokens_in: int, tokens_out: int, failures: int, fail_rate: float, avg_cost_per_run: float, avg_duration_ms: float}
     */
    #[Computed]
    public function summary(): array
    {
        $row = $this->baseQuery()
            ->selectRaw('
                COALESCE(SUM(e.cost_micro_cents), 0) as cost,
                COUNT(*) as runs,
                COALESCE(SUM(e.tokens_input), 0) as tokens_in,
                COALESCE(SUM(e.tokens_output), 0) as tokens_out,
                COALESCE(SUM(CASE WHEN e.status = ? THEN 1 ELSE 0 END), 0) as failures,
                COALESCE(AVG(e.duration_ms), 0) as avg_duration_ms
            ', ['failed'])
            ->first();

        $runs = (int) ($row->runs ?? 0);
        $failures = (int) ($row->failures ?? 0);

        return [
            'cost' => (int) ($row->cost ?? 0),
            'runs' => $runs,
            'tokens_in' => (int) ($row->tokens_in ?? 0),
            'tokens_out' => (int) ($row->tokens_out ?? 0),
            'failures' => $failures,
            'fail_rate' => $runs > 0 ? ($failures / $runs) : 0.0,
            'avg_cost_per_run' => $runs > 0 ? ($row->cost / $runs) : 0.0,
            'avg_duration_ms' => (float) ($row->avg_duration_ms ?? 0),
        ];
    }

    /**
     * Per-provider breakdown. Ordered by cost descending — the lever is at the top.
     */
    #[Computed]
    public function byProvider(): array
    {
        return $this->baseQuery()
            ->selectRaw('
                COALESCE(e.provider, ?) as provider,
                COUNT(*) as runs,
                COALESCE(SUM(e.tokens_input), 0) as tokens_in,
                COALESCE(SUM(e.tokens_output), 0) as tokens_out,
                COALESCE(SUM(e.cost_micro_cents), 0) as cost,
                COALESCE(AVG(e.cost_micro_cents), 0) as avg_cost,
                COALESCE(AVG(e.duration_ms), 0) as avg_duration_ms,
                COALESCE(SUM(CASE WHEN e.status = ? THEN 1 ELSE 0 END), 0) as failures
            ', ['—', 'failed'])
            ->groupBy('e.provider')
            ->orderByDesc('cost')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * Per-template breakdown — same shape, grouped by template_key + provider so
     * the side-by-side "same template, two providers" comparison is one row each.
     */
    #[Computed]
    public function byTemplate(): array
    {
        return $this->baseQuery()
            ->selectRaw('
                COALESCE(e.template_key, ?) as template_key,
                COALESCE(e.provider, ?) as provider,
                MAX(e.template_version) as max_version,
                COUNT(*) as runs,
                COALESCE(SUM(e.cost_micro_cents), 0) as cost,
                COALESCE(AVG(e.cost_micro_cents), 0) as avg_cost,
                COALESCE(AVG(e.duration_ms), 0) as avg_duration_ms,
                COALESCE(SUM(CASE WHEN e.status = ? THEN 1 ELSE 0 END), 0) as failures
            ', ['—', '—', 'failed'])
            ->groupBy('e.template_key', 'e.provider')
            ->orderByDesc('cost')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * Daily cost + run-count for the timeline strip. Days with no runs are
     * filled with zero so the strip doesn't get holes.
     * @return array<int, array{day: string, cost: int, runs: int}>
     */
    #[Computed]
    public function daily(): array
    {
        $w = $this->window();
        $rows = $this->baseQuery()
            ->selectRaw('DATE(e.created_at) as day, COALESCE(SUM(e.cost_micro_cents), 0) as cost, COUNT(*) as runs')
            ->groupByRaw('DATE(e.created_at)')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $out = [];
        $cursor = $w['from']->startOfDay();
        $end = $w['to']->startOfDay();
        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);
            $out[] = [
                'day' => $key,
                'cost' => (int) ($row->cost ?? 0),
                'runs' => (int) ($row->runs ?? 0),
            ];
            $cursor = $cursor->addDay();
        }
        return $out;
    }

    public function setRange(string $range): void
    {
        $this->range = in_array($range, ['30d', '90d', 'month', 'last_month'], true) ? $range : '30d';
        unset($this->summary, $this->byProvider, $this->byTemplate, $this->daily);
    }

    public function render()
    {
        return view('inbox::livewire.costs.index')
            ->layout('platform::layouts.app');
    }
}
