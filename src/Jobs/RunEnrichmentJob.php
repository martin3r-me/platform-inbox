<?php

namespace Platform\Inbox\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Inbox\Models\InboxEnrichmentTemplate;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemEnrichment;
use Platform\Inbox\Services\Enrichment\EnrichmentProviderRegistry;

class RunEnrichmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(
        public int $inboxItemId,
        public int $templateId,
        public bool $markPrimary = true,
    ) {}

    public function handle(EnrichmentProviderRegistry $registry): void
    {
        $item = InboxItem::with('participants')->find($this->inboxItemId);
        if (!$item) {
            return;
        }
        $template = InboxEnrichmentTemplate::find($this->templateId);
        if (!$template || !$template->is_active) {
            return;
        }

        // Avoid duplicate runs of the same template_id+version on the same item.
        $existing = InboxItemEnrichment::query()
            ->where('inbox_item_id', $item->id)
            ->where('template_id', $template->id)
            ->where('template_version', $template->version)
            ->whereIn('status', [
                InboxItemEnrichment::STATUS_PENDING,
                InboxItemEnrichment::STATUS_RUNNING,
                InboxItemEnrichment::STATUS_DONE,
            ])
            ->latest()
            ->first();
        if ($existing && $existing->status === InboxItemEnrichment::STATUS_DONE) {
            return;
        }

        $enrichment = $existing ?? InboxItemEnrichment::create([
            'inbox_item_id' => $item->id,
            'template_id' => $template->id,
            'template_key' => $template->key,
            'template_version' => $template->version,
            'status' => InboxItemEnrichment::STATUS_RUNNING,
            'run_at' => now(),
        ]);
        if ($existing) {
            $enrichment->update([
                'status' => InboxItemEnrichment::STATUS_RUNNING,
                'run_at' => now(),
                'error_message' => null,
            ]);
        }

        $provider = $registry->resolve($template);
        if (!$provider) {
            $enrichment->update([
                'status' => InboxItemEnrichment::STATUS_FAILED,
                'error_message' => 'Kein Provider gefunden für ' . ($template->preferred_provider ?: '(unbekannt)'),
            ]);
            return;
        }

        try {
            $result = $provider->run($item, $template);
        } catch (\Throwable $e) {
            Log::warning('Inbox: enrichment provider threw', [
                'item_id' => $item->id,
                'template_key' => $template->key,
                'error' => $e->getMessage(),
            ]);
            $enrichment->update([
                'status' => InboxItemEnrichment::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            return;
        }

        if (!$result->ok) {
            $enrichment->update([
                'status' => InboxItemEnrichment::STATUS_FAILED,
                'provider' => $provider->key() . ':' . $result->providerModel,
                'provider_model' => $result->providerModel,
                'error_message' => $result->errorMessage,
            ]);
            return;
        }

        $enrichment->update([
            'status' => InboxItemEnrichment::STATUS_DONE,
            'provider' => $provider->key() . ':' . $result->providerModel,
            'provider_model' => $result->providerModel,
            'output' => $result->output,
            'tokens_input' => $result->tokensInput,
            'tokens_output' => $result->tokensOutput,
            'cost_micro_cents' => $result->costMicroCents,
            'duration_ms' => $result->durationMs,
            'confidence' => $result->confidence,
        ]);

        if ($this->markPrimary) {
            // Demote previous primaries for this item, promote this one.
            InboxItemEnrichment::where('inbox_item_id', $item->id)
                ->where('id', '!=', $enrichment->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
            $enrichment->update(['is_primary' => true]);
        }
    }
}
