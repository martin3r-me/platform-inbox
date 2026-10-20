<?php

namespace Platform\Inbox\Services\Enrichment;

use Platform\Core\Services\LLMProviderRegistry;
use Platform\Core\Services\LlmCostCalculator;
use Platform\Inbox\Contracts\InboxEnrichmentProvider;
use Platform\Inbox\Models\InboxEnrichmentTemplate;
use Platform\Inbox\Models\InboxItem;

/**
 * Der EINZIGE, anbieter-agnostische Enrichment-Provider.
 *
 * Er kennt kein Modell und keinen Anbieter: Er routet über die zentrale
 * Core\LLMProviderRegistry anhand von template.preferred_provider ("name:model").
 * Neues Backend = ein Core-Provider registrieren — hier ändert sich NICHTS.
 *
 * Die Transformation (Prompt füllen, JSON parsen, Kosten, EnrichmentResult) ist
 * vendor-neutral. Den Dialekt des Struktur-Zwangs (json_schema) honoriert jeder
 * Core-Provider selbst (OpenAI: response_format/strict; Anthropic: System-
 * Instruktion; Local: response_format). Fällt der angefragte Anbieter aus, greift
 * der Default der Registry — so wird z.B. "local als Standard, Cloud als Fallback"
 * reine Konfiguration.
 */
class GatewayEnrichmentProvider implements InboxEnrichmentProvider
{
    /**
     * Template-Konvention → Core-Provider-Name. Die Templates sprechen von
     * "claude:…", die Core-Registry registriert Anthropic unter "anthropic".
     */
    protected const PROVIDER_ALIASES = [
        'claude' => 'anthropic',
        'anthropic' => 'anthropic',
        'openai' => 'openai',
        'local' => 'local',
    ];

    public function key(): string
    {
        return 'gateway';
    }

    public function supports(InboxEnrichmentTemplate $template): bool
    {
        // Vendor-agnostisch — bedient jedes Template.
        return true;
    }

    public function run(InboxItem $item, InboxEnrichmentTemplate $template): EnrichmentResult
    {
        $registry = app(LLMProviderRegistry::class);

        [$name, $model] = $this->parsePreferred((string) ($template->preferred_provider ?? ''));
        $coreName = self::PROVIDER_ALIASES[$name] ?? $name;

        $provider = $coreName !== '' ? $registry->get($coreName) : null;

        // Fallback: angefragter Anbieter fehlt/nicht verfügbar → Default der Registry.
        if ($provider === null || ! $provider->isAvailable()) {
            $provider = $registry->getDefaultProvider();
            $model = ''; // Default-Provider bestimmt sein eigenes Default-Modell
        }
        if ($provider === null) {
            return EnrichmentResult::fail('Kein verfügbarer LLM-Provider (Core LLMProviderRegistry leer).', $model);
        }
        if ($model === '') {
            $model = $provider->getDefaultModel();
        }

        $system = $this->buildSystem($template);
        $userPrompt = $this->fillPrompt((string) $template->prompt_template, $item);

        $options = [
            'model' => $model,
            'system' => $system,
            'max_tokens' => 4096,
        ];
        if (is_array($template->output_schema) && ! empty($template->output_schema['type'])) {
            $options['json_schema'] = $template->output_schema;
            $options['json_schema_name'] = (string) ($template->key ?? 'enrichment');
        }

        $startedAt = microtime(true);
        try {
            $result = $provider->chat([['role' => 'user', 'content' => $userPrompt]], $options);
        } catch (\Throwable $e) {
            // Core wirft bei HTTP-Fehlern (inkl. "credit balance too low") mit Body
            // und loggt zentral — landet hier als error_message am Lauf.
            return EnrichmentResult::fail($e->getMessage(), $model);
        }
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $output = $this->parseJson((string) ($result['content'] ?? ''));
        if ($output === null) {
            return EnrichmentResult::fail('Provider returned non-JSON content.', $model);
        }

        $usage = $this->normalizeUsage($result['usage'] ?? []);
        $providerModel = (string) ($result['model'] ?? $model);

        return EnrichmentResult::ok(
            output: $output,
            providerModel: $providerModel,
            tokensInput: $usage['input_tokens'] ?: null,
            tokensOutput: $usage['output_tokens'] ?: null,
            costMicroCents: $this->estimateCost($providerModel, $usage),
            durationMs: $durationMs,
        );
    }

    /**
     * "name:model" → [name, model]. Leer → ['', ''].
     *
     * @return array{0: string, 1: string}
     */
    protected function parsePreferred(string $preferred): array
    {
        if (trim($preferred) === '') {
            return ['', ''];
        }
        $parts = explode(':', $preferred, 2);
        return [trim($parts[0]), trim($parts[1] ?? '')];
    }

    protected function buildSystem(InboxEnrichmentTemplate $template): string
    {
        $segments = [];
        if (! empty($template->system_prompt)) {
            $segments[] = $template->system_prompt;
        }
        $segments[] = 'Antworte ausschließlich als gültiges JSON-Objekt. Keine Fließtext-Kommentare, keine Markdown-Fences.';
        return implode("\n\n", $segments);
    }

    protected function fillPrompt(string $template, InboxItem $item): string
    {
        $participantsList = $item->participants
            ->map(fn ($p) => trim(($p->role ?? '') . ': ' . ($p->display_name ?: $p->identifier ?: '')))
            ->filter()
            ->implode("\n");

        $body = $item->body ?? $item->preview ?? '';
        $bodyForPrompt = mb_strlen($body) > 12000
            ? mb_substr($body, 0, 12000) . "\n…\n[truncated, full body persists]"
            : $body;

        return strtr($template, [
            '{body}' => $bodyForPrompt,
            '{subject}' => (string) ($item->subject ?? ''),
            '{sender}' => (string) ($item->sender_label ?: $item->sender_identifier ?: ''),
            '{channel}' => (string) ($item->channel?->value ?? ''),
            '{language}' => (string) ($item->language ?? 'de'),
            '{participants_list}' => $participantsList,
        ]);
    }

    protected function parseJson(string $content): ?array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed);
        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Vereinheitlicht Token-Usage über Anbieter hinweg auf input_tokens/output_tokens
     * (Anthropic: input/output_tokens; OpenAI/Local: prompt/completion_tokens).
     *
     * @param array<string, mixed> $usage
     * @return array<string, mixed>
     */
    protected function normalizeUsage(array $usage): array
    {
        $in = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $out = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        return array_merge($usage, ['input_tokens' => $in, 'output_tokens' => $out]);
    }

    /**
     * Kosten über den zentralen Core-Calculator (USD) → micro-cents (1e-6 USD).
     *
     * @param array<string, mixed> $usage  bereits normalisiert (input_tokens/output_tokens)
     */
    protected function estimateCost(string $model, array $usage): ?int
    {
        try {
            $usd = app(LlmCostCalculator::class)->costUsd($model, $usage);
            return (int) round($usd * 1_000_000);
        } catch (\Throwable) {
            return null;
        }
    }
}
