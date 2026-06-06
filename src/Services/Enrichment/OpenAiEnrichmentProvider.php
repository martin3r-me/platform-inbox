<?php

namespace Platform\Inbox\Services\Enrichment;

use Platform\Core\Services\OpenAiService;
use Platform\Inbox\Contracts\InboxEnrichmentProvider;
use Platform\Inbox\Models\InboxEnrichmentTemplate;
use Platform\Inbox\Models\InboxItem;

/**
 * Default LLM provider. Uses the platform's OpenAiService and forces a strict
 * JSON object output matching the template's output_schema.
 *
 * Pricing — rough OpenAI pricing per 1M tokens (Q2/2026):
 *   gpt-4o-mini: $0.15 in, $0.60 out
 *   gpt-4o:      $2.50 in, $10.00 out
 * Costs are stored in micro-cents (1/10000 of a cent) for precision.
 */
class OpenAiEnrichmentProvider implements InboxEnrichmentProvider
{
    /** @var array<string, array{in: int, out: int}>  micro-cents per token */
    protected array $pricingMicroCentsPerToken = [
        // 1 micro-cent = 1e-6 USD; per token = per-million-token-price * 1e-2 / 1
        // Easier: just store input/output cost per token in micro-cents directly.
        // gpt-4o-mini  $0.15 / 1M = 0.0015 cents per token = 15 micro-cents per token
        'gpt-4o-mini' => ['in' => 15, 'out' => 60],
        'gpt-4o' => ['in' => 250, 'out' => 1000],
    ];

    public function key(): string
    {
        return 'openai';
    }

    public function supports(InboxEnrichmentTemplate $template): bool
    {
        $preferred = $template->preferred_provider ?? '';
        // Empty preferred_provider → support as default fallback.
        if ($preferred === '') {
            return true;
        }
        return str_starts_with($preferred, 'openai:');
    }

    public function run(InboxItem $item, InboxEnrichmentTemplate $template): EnrichmentResult
    {
        $model = $this->resolveModel($template);
        $startedAt = microtime(true);

        $prompt = $this->fillPrompt($template->prompt_template, $item);

        $messages = [];
        if (!empty($template->system_prompt)) {
            $messages[] = ['role' => 'system', 'content' => $template->system_prompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        try {
            $service = app(OpenAiService::class);
            $response = $service->chat(
                $messages,
                $model,
                [
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.2,
                ],
            );
        } catch (\Throwable $e) {
            return EnrichmentResult::fail($e->getMessage(), $model);
        }

        $content = $response['content'] ?? '';
        $usage = $response['usage'] ?? [];
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $output = $this->parseJson($content);
        if ($output === null) {
            return EnrichmentResult::fail('Provider returned non-JSON content.', $model);
        }

        $tokensIn = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $tokensOut = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);

        return EnrichmentResult::ok(
            output: $output,
            providerModel: $model,
            tokensInput: $tokensIn ?: null,
            tokensOutput: $tokensOut ?: null,
            costMicroCents: $this->estimateCost($model, $tokensIn, $tokensOut),
            durationMs: $durationMs,
        );
    }

    protected function resolveModel(InboxEnrichmentTemplate $template): string
    {
        $preferred = $template->preferred_provider ?? '';
        if (str_starts_with($preferred, 'openai:')) {
            return substr($preferred, strlen('openai:'));
        }
        return 'gpt-4o-mini';
    }

    protected function fillPrompt(string $template, InboxItem $item): string
    {
        $participantsList = $item->participants
            ->map(fn ($p) => trim(($p->role ?? '') . ': ' . ($p->display_name ?: $p->identifier ?: '')))
            ->filter()
            ->implode("\n");

        $body = $item->body ?? $item->preview ?? '';
        // Keep prompts tame — extremely long bodies get truncated; full body remains in DB.
        $bodyForPrompt = mb_strlen($body) > 12000
            ? mb_substr($body, 0, 12000) . "\n…\n[truncated, full body persists]"
            : $body;

        $vars = [
            '{body}' => $bodyForPrompt,
            '{subject}' => (string) ($item->subject ?? ''),
            '{sender}' => (string) ($item->sender_label ?: $item->sender_identifier ?: ''),
            '{channel}' => (string) ($item->channel?->value ?? ''),
            '{language}' => (string) ($item->language ?? 'de'),
            '{participants_list}' => $participantsList,
        ];

        return strtr($template, $vars);
    }

    protected function parseJson(string $content): ?array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }
        // Strip common ``` fences if a model slipped them in.
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
