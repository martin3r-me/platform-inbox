<?php

namespace Platform\Inbox\Services\Enrichment;

use Platform\Inbox\Contracts\InboxEnrichmentProvider;
use Platform\Inbox\Models\InboxEnrichmentTemplate;
use Platform\Inbox\Models\InboxItem;

/**
 * Anthropic Claude provider. Mirrors OpenAiEnrichmentProvider's contract:
 * builds the prompt from the template, forces strict JSON output, tracks
 * token usage + cost so provider A/B comparisons are immediate.
 *
 * Pricing — Anthropic Q2/2026 per 1M tokens:
 *   claude-haiku-4-5  : $0.80 in  / $4.00 out
 *   claude-sonnet-4-6 : $3.00 in  / $15.00 out
 *   claude-opus-4-7   : $15.00 in / $75.00 out
 * Stored in micro-cents (1/10000 of a cent).
 */
class ClaudeEnrichmentProvider implements InboxEnrichmentProvider
{
    /** @var array<string, array{in: int, out: int}> micro-cents per token */
    protected array $pricingMicroCentsPerToken = [
        'claude-haiku-4-5'    => ['in' => 80,   'out' => 400],
        'claude-sonnet-4-6'   => ['in' => 300,  'out' => 1500],
        'claude-opus-4-7'     => ['in' => 1500, 'out' => 7500],
    ];

    public function key(): string
    {
        return 'claude';
    }

    public function supports(InboxEnrichmentTemplate $template): bool
    {
        return str_starts_with((string) $template->preferred_provider, 'claude:');
    }

    public function run(InboxItem $item, InboxEnrichmentTemplate $template): EnrichmentResult
    {
        $model = $this->resolveModel($template);
        $startedAt = microtime(true);

        // Zentrale Model-Schicht statt eigenem HTTP-Call: der Core-AnthropicProvider
        // kapselt Endpoint, Key (config ai.anthropic.*), API-Version, Fehler-Logging
        // und Kostenbasis an EINER Stelle. Dadurch kann z.B. ein leeres Guthaben nicht
        // mehr still nur in dieser Pipeline auflaufen (Core loggt den HTTP-Fehler), und
        // Modellwahl/Provider sind konsistent mit dem Rest der Plattform.
        $provider = app(\Platform\Core\Services\LLMProviderRegistry::class)->get('anthropic');
        if ($provider === null || ! $provider->isAvailable()) {
            return EnrichmentResult::fail('Anthropic-Provider nicht verfügbar (Core LLMProviderRegistry).', $model);
        }

        $userPrompt = $this->fillPrompt($template->prompt_template, $item);

        // JSON-Erzwingung über System-Instruktion (Anthropic kennt kein response_format).
        $systemSegments = [];
        if (!empty($template->system_prompt)) {
            $systemSegments[] = $template->system_prompt;
        }
        $systemSegments[] = 'Antworte ausschließlich als gültiges JSON-Objekt. Keine Fließtext-Kommentare, keine Markdown-Fences.';
        $system = implode("\n\n", $systemSegments);

        try {
            $result = $provider->chat(
                [['role' => 'user', 'content' => $userPrompt]],
                ['model' => $model, 'system' => $system, 'max_tokens' => 4096],
            );
        } catch (\Throwable $e) {
            // Core wirft bei HTTP-Fehlern (inkl. "credit balance too low") eine
            // RuntimeException mit dem API-Body — landet als error_message am Lauf.
            return EnrichmentResult::fail($e->getMessage(), $model);
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        // Core liefert den Text bereits extrahiert unter 'content'.
        $output = $this->parseJson((string) ($result['content'] ?? ''));
        if ($output === null) {
            return EnrichmentResult::fail('Provider returned non-JSON content.', $model);
        }

        $usage = $result['usage'] ?? [];
        $tokensIn = (int) ($usage['input_tokens'] ?? 0);
        $tokensOut = (int) ($usage['output_tokens'] ?? 0);

        return EnrichmentResult::ok(
            output: $output,
            providerModel: (string) ($result['model'] ?? $model),
            tokensInput: $tokensIn ?: null,
            tokensOutput: $tokensOut ?: null,
            costMicroCents: $this->estimateCost($model, $tokensIn, $tokensOut),
            durationMs: $durationMs,
        );
    }

    protected function resolveModel(InboxEnrichmentTemplate $template): string
    {
        $preferred = (string) ($template->preferred_provider ?? '');
        if (str_starts_with($preferred, 'claude:')) {
            return substr($preferred, strlen('claude:'));
        }
        return 'claude-haiku-4-5';
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
        // Strip code fences just in case the model adds them despite instruction.
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed);
        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : null;
    }

    protected function estimateCost(string $model, int $tokensIn, int $tokensOut): ?int
    {
        $rates = $this->pricingMicroCentsPerToken[$model] ?? null;
        if (!$rates) {
            return null;
        }
        return $tokensIn * $rates['in'] + $tokensOut * $rates['out'];
    }
}
