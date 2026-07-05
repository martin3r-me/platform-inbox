<?php

namespace Platform\Inbox\Services\Enrichment;

use Illuminate\Support\Facades\Log;
use Platform\Inbox\Jobs\RunEnrichmentJob;
use Platform\Inbox\Models\InboxEnrichmentTemplate;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemEnrichment;

/**
 * Single decision point for "should we dispatch a RunEnrichmentJob for this
 * item right now, and if yes with which template". Callers pass a mode; the
 * dispatcher applies the right policy variant, dedupes against existing
 * rows, and either dispatches or returns the skip reason for logging.
 *
 * Modes:
 *   - 'auto' — used by ingestion (fresh cron inserts, audio ingest, etc.).
 *              Enriches ONLY VIP senders. Everyone else waits for the user
 *              to open the item ('user' mode) so we don't burn budget on
 *              mails that never get read.
 *   - 'user' — used by V2 cockpit selectItem. Runs the full policy but
 *              skips the VIP check.
 *   - 'force' — used by inbox:reenrich --force. Skips policy entirely
 *               (still dedupes against a running/pending row so we don't
 *               fire twice concurrently).
 */
class EnrichmentDispatcher
{
    public function __construct(protected EnrichmentPolicy $policy)
    {
    }

    /**
     * Try to dispatch. Returns null on dispatched, string reason on skipped.
     */
    public function dispatchIfEligible(InboxItem $item, string $mode = 'user'): ?string
    {
        $channel = $item->channel?->value ?? (is_string($item->channel) ? $item->channel : null);
        if (!$channel) {
            return 'no channel';
        }

        $template = InboxEnrichmentTemplate::defaultForChannel($channel, $item->team_id);
        if (!$template) {
            return "no default template for channel={$channel}";
        }

        // Dedup: don't queue another job if a done primary already exists in
        // the right shape, or if a pending/running row is already in flight.
        $existing = InboxItemEnrichment::query()
            ->where('inbox_item_id', $item->id)
            ->where('template_id', $template->id)
            ->where('template_version', $template->version)
            ->latest()
            ->first();
        if ($existing) {
            if ($existing->status === InboxItemEnrichment::STATUS_DONE
                && $existing->is_primary
                && $this->hasUsableOutput($existing->output ?? [])
            ) {
                return 'already enriched';
            }
            if (in_array($existing->status, [
                InboxItemEnrichment::STATUS_PENDING,
                InboxItemEnrichment::STATUS_RUNNING,
            ], true)) {
                return 'already in flight';
            }
        }

        if ($mode !== 'force') {
            $reason = $this->policy->skipReason($item, vipOnly: $mode === 'auto');
            if ($reason !== null) {
                return $reason;
            }
        }

        try {
            RunEnrichmentJob::dispatch($item->id, $template->id);
        } catch (\Throwable $e) {
            Log::warning('Inbox: enrichment dispatch failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            return 'dispatch error: ' . $e->getMessage();
        }

        return null;
    }

    /**
     * Match the cockpit's readable-shape check: an enrichment counts as
     * "usable" only if at least one of the fields the V2 cockpit renders
     * is non-empty. Otherwise we re-dispatch on next selection so an
     * old broken row doesn't permanently block a working one.
     */
    protected function hasUsableOutput(array $output): bool
    {
        foreach (['tldr', 'headline', 'summary'] as $key) {
            $val = $output[$key] ?? null;
            if (is_string($val) && trim($val) !== '') {
                return true;
            }
        }
        $items = $output['action_items'] ?? null;
        return is_array($items) && count($items) > 0;
    }
}
