<?php

namespace Platform\Inbox\Services\Enrichment;

use Illuminate\Support\Facades\Http;
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
        $apiKey = (string) config('ai.anthropic.api_key', '');
        if ($apiKey === '') {
            return EnrichmentResult::fail('ANTHROPIC_API_KEY nicht konfiguriert.', $this->resolveModel($template));
        }

        $model = $this->resolveModel($template);
        $startedAt = microtime(true);

        $userPrompt = $this->fillPrompt($template->prompt_template, $item);

        // Anthropic Messages API: JSON enforcement is done via explicit system
        // instruction. The model honours "respond ONLY with JSON" reliably.
        $systemSegments = [];
        if (!empty($template->system_prompt)) {
            $systemSegments[] = $template->system_prompt;
        }
        $systemSegments[] = 'Antworte ausschließlich als gültiges JSON-Objekt. Keine Fließtext-Kommentare, keine Markdown-Fences.';
        $system = implode("\n\n", $systemSegments);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 4096,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);
        } catch (\Throwable $e) {
            return EnrichmentResult::fail($e->getMessage(), $model);
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (!$response->successful()) {
            return EnrichmentResult::fail(
                sprintf('Anthropic HTTP %d: %s', $response->status(), mb_substr($response->body(), 0, 300)),
                $model,
            );
        }

        $data = $response->json();
        $content = $this->extractText($data);
        $usage = $data['usage'] ?? [];

        $output = $this->parseJson($content);
        if ($output === null) {
            return EnrichmentResult::fail('Provider returned non-JSON content.', $model);
        }

        $tokensIn = (int) ($usage['input_tokens'] ?? 0);
        $tokensOut = (int) ($usage['output_tokens'] ?? 0);

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

    protected function extractText(array $data): string
    {
        $parts = $data['content'] ?? [];
        $texts = [];
        foreach ($parts as $part) {
            if (($part['type'] ?? '') === 'text' && isset($part['text'])) {
                $texts[] = (string) $part['text'];
            }
        }
        return implode("\n", $texts);
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
