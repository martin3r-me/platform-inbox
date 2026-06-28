<?php

namespace Platform\Inbox\Console\Commands;

use Illuminate\Console\Command;
use Platform\Inbox\Jobs\RunEnrichmentJob;
use Platform\Inbox\Models\InboxEnrichmentTemplate;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemEnrichment;

/**
 * Re-runs the default enrichment for inbox items whose primary enrichment is
 * missing the keys the V2 cockpit reads (tldr / headline / action_items /
 * summary). Built to backfill the items that were enriched before the
 * OpenAI provider was switched to Structured Outputs — those have
 * status=done rows with a fantasy schema, so the cockpit silently rendered
 * nothing.
 *
 * Examples:
 *   php artisan inbox:reenrich --since=14
 *   php artisan inbox:reenrich --channel=mail --force
 *   php artisan inbox:reenrich --dry-run
 */
class ReenrichInboxItems extends Command
{
    protected $signature = 'inbox:reenrich
        {--since=14 : Zeitfenster in Tagen ab heute}
        {--channel= : Auf einen Channel begrenzen (mail/meeting/call/message/recording)}
        {--force : Auch Items neu enrichen, deren Output bereits matched}
        {--dry-run : Nur zählen, nichts dispatchen oder löschen}
        {--limit=2000 : Max. Anzahl Items pro Lauf}';

    protected $description = 'Backfill / re-run default enrichment für Items, deren Output das erwartete Schema verfehlt.';

    /**
     * Felder, die die V2 Cockpits aus dem Enrichment-Output lesen. Hat ein
     * primary done weder das eine noch das andere, ist es de facto unsichtbar
     * und wir behandeln das Item als nicht-enriched.
     */
    protected array $expectedKeys = ['tldr', 'headline', 'action_items', 'summary'];

    public function handle(): int
    {
        $since = (int) $this->option('since');
        $channel = $this->option('channel');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $query = InboxItem::query()
            ->where('received_at', '>=', now()->subDays($since));
        if ($channel) {
            $query->where('channel', $channel);
        }
        $items = $query->orderByDesc('received_at')->limit($limit)->get();

        if ($items->isEmpty()) {
            $this->info('Keine Items im Zeitfenster gefunden.');
            return self::SUCCESS;
        }

        $templateCache = [];
        $stats = ['dispatched' => 0, 'skipped_ok' => 0, 'skipped_no_template' => 0, 'cleared' => 0];

        foreach ($items as $item) {
            $ch = $item->channel?->value;
            if (!$ch) {
                continue;
            }

            $cacheKey = $ch . '|' . ($item->team_id ?? 0);
            if (!array_key_exists($cacheKey, $templateCache)) {
                $templateCache[$cacheKey] = InboxEnrichmentTemplate::defaultForChannel($ch, $item->team_id);
            }
            $template = $templateCache[$cacheKey];
            if (!$template) {
                $stats['skipped_no_template']++;
                continue;
            }

            $primary = InboxItemEnrichment::query()
                ->where('inbox_item_id', $item->id)
                ->where('is_primary', true)
                ->where('status', InboxItemEnrichment::STATUS_DONE)
                ->latest()
                ->first();

            $needsRerun = $force || !$primary || !$this->matchesExpectedShape($primary->output ?? []);

            if (!$needsRerun) {
                $stats['skipped_ok']++;
                continue;
            }

            if ($dryRun) {
                $stats['dispatched']++;
                continue;
            }

            // Bestehende Enrichment-Rows für dieses (Item, Template, Version)
            // löschen — sonst short-circuited RunEnrichmentJob auf die alte
            // done-Row und macht nichts.
            InboxItemEnrichment::query()
                ->where('inbox_item_id', $item->id)
                ->where('template_id', $template->id)
                ->where('template_version', $template->version)
                ->delete();
            $stats['cleared']++;

            RunEnrichmentJob::dispatch($item->id, $template->id);
            $stats['dispatched']++;
        }

        $this->table(
            ['Status', 'Count'],
            [
                ['Dispatched', $stats['dispatched']],
                ['Cleared old rows', $stats['cleared']],
                ['Skipped (already OK)', $stats['skipped_ok']],
                ['Skipped (no template)', $stats['skipped_no_template']],
                ['Items scanned', $items->count()],
            ],
        );

        if ($dryRun) {
            $this->warn('--dry-run aktiv — nichts geändert.');
        }

        return self::SUCCESS;
    }

    /**
     * Output passes shape check if it carries at least one of the keys the
     * V2 cockpits render. Anything else (z.B. das alte "inhalt"/"absender"-
     * Fantasieschema von vor Structured Outputs) bleibt unsichtbar und
     * gilt als "nicht-enriched".
     */
    protected function matchesExpectedShape(array $output): bool
    {
        foreach ($this->expectedKeys as $key) {
            if (array_key_exists($key, $output) && $output[$key] !== null && $output[$key] !== '' && $output[$key] !== []) {
                return true;
            }
        }
        return false;
    }
}
