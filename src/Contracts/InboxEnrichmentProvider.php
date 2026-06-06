<?php

namespace Platform\Inbox\Contracts;

use Platform\Inbox\Models\InboxEnrichmentTemplate;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Services\Enrichment\EnrichmentResult;

interface InboxEnrichmentProvider
{
    /**
     * Provider key — must match the part before ":" in template.preferred_provider.
     * e.g. "openai", "claude", "lemur".
     */
    public function key(): string;

    /**
     * Whether this provider can handle a given template (e.g. supports the
     * channel/model combination requested by preferred_provider).
     */
    public function supports(InboxEnrichmentTemplate $template): bool;

    /**
     * Run the enrichment for an item using a given template. The provider
     * is responsible for filling the prompt template with item data,
     * calling its underlying LLM API, parsing the JSON output, and returning
     * a structured EnrichmentResult including token + cost metadata.
     */
    public function run(InboxItem $item, InboxEnrichmentTemplate $template): EnrichmentResult;
}
