<?php

namespace Platform\Inbox\Services\Enrichment;

use Platform\Inbox\Models\InboxItem;

/**
 * Gate for the RunEnrichmentJob dispatch. Cheap sanity checks before
 * spending ~0.2¢ on an LLM call for something no one wants to read.
 *
 * Called from both fresh ingestion and the backfill command so a muted
 * sender or a low-signal item doesn't accidentally get re-enriched on
 * the next inbox:reenrich run.
 *
 * Returns null when enrichment should proceed; a short string reason
 * when it should be skipped — the reason gets logged so the user can
 * later reconsider ("actually Moss card alerts ARE actionable, remove
 * @getmoss.com from skip list").
 */
class EnrichmentPolicy
{
    /**
     * Should this item be enriched? Returns null on GO, string reason on SKIP.
     */
    public function skipReason(InboxItem $item): ?string
    {
        // 1) Only new items. Snoozed / done / ignored either won't be seen
        //    or the user explicitly declared them uninteresting.
        $status = $item->status?->value ?? (is_string($item->status) ? $item->status : null);
        if ($status !== null && $status !== 'new') {
            return "status={$status}";
        }

        // 2) Body must have enough substance for a summary to add value.
        //    Meeting reminders, 1-liner receipts etc. score below this bar.
        $body = trim((string) ($item->body ?? $item->preview ?? ''));
        $minBody = (int) config('inbox.enrichment.min_body_length', 200);
        if ($minBody > 0 && mb_strlen($body) < $minBody) {
            return 'body too short (' . mb_strlen($body) . ' < ' . $minBody . ')';
        }

        // 3) Sender pattern list — social media recaps, newsletter platforms,
        //    generic notification senders. Prefix "/" activates regex; plain
        //    strings match as case-insensitive substring on sender_identifier.
        $sender = strtolower((string) ($item->sender_identifier ?? ''));
        if ($sender !== '') {
            $patterns = (array) config('inbox.enrichment.skip_sender_patterns', []);
            foreach ($patterns as $p) {
                $p = (string) $p;
                if ($p === '') {
                    continue;
                }
                if ($p[0] === '/') {
                    if (@preg_match($p, $sender) === 1) {
                        return "sender matches {$p}";
                    }
                    continue;
                }
                if (str_contains($sender, strtolower($p))) {
                    return "sender contains '{$p}'";
                }
            }
        }

        return null;
    }
}
