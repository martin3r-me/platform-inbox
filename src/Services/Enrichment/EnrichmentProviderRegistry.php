<?php

namespace Platform\Inbox\Services\Enrichment;

use Platform\Inbox\Contracts\InboxEnrichmentProvider;
use Platform\Inbox\Models\InboxEnrichmentTemplate;

/**
 * Holds all registered enrichment providers. Resolves the right one for a
 * given template based on its preferred_provider (with graceful fallback).
 */
class EnrichmentProviderRegistry
{
    /** @var array<string, InboxEnrichmentProvider> */
    protected array $providers = [];

    protected ?string $defaultKey = null;

    public function register(InboxEnrichmentProvider $provider, bool $asDefault = false): void
    {
        $this->providers[$provider->key()] = $provider;
        if ($asDefault || $this->defaultKey === null) {
            $this->defaultKey = $provider->key();
        }
    }

    public function resolve(InboxEnrichmentTemplate $template): ?InboxEnrichmentProvider
    {
        // Try the explicitly preferred provider first.
        $preferred = $template->preferred_provider ?? '';
        if ($preferred !== '') {
            $providerKey = explode(':', $preferred, 2)[0];
            $candidate = $this->providers[$providerKey] ?? null;
            if ($candidate && $candidate->supports($template)) {
                return $candidate;
            }
        }

        // Fallback: walk all registered providers, pick the first that supports.
        foreach ($this->providers as $provider) {
            if ($provider->supports($template)) {
                return $provider;
            }
        }

        return null;
    }

    /** @return array<string, InboxEnrichmentProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
