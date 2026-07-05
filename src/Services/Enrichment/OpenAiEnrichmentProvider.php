<?php

namespace Platform\Inbox\Services\Enrichment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        // Structured Outputs: das Template-Schema wird an die API gebunden,
        // damit das Model GENAU die erwarteten Felder produziert.
        //
        // Bypass Platform\Core\Services\OpenAiService: der Service ruft die
        // Responses-API (/v1/responses) auf und lässt `response_format` aus
        // den options fallen — Structured Outputs bräuchte dort `text.format`
        // statt `response_format`. Für die Anreicherung wollen wir aber die
        // simplere Chat-Completions-API mit json_schema/strict, und wir
        // brauchen sowieso keine Tools/Reasoning-Features. Direkter Call an
        // /v1/chat/completions ist kürzer, günstiger und tut GENAU das was
        // hier gebraucht wird.
        $requestBody = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
        ];

        $schema = $template->output_schema ?? null;
        if (is_array($schema) && !empty($schema['type'])) {
            $requestBody['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => preg_replace('/[^A-Za-z0-9_]+/', '_', (string) ($template->key ?? 'enrichment')),
                    'schema' => $this->adaptSchemaForStrict($schema),
                    'strict' => true,
                ],
            ];
        }

        $apiKey = $this->resolveApiKey();
        if (!$apiKey) {
            return EnrichmentResult::fail('OPENAI_API_KEY not configured.', $model);
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout(60)
                ->post('https://api.openai.com/v1/chat/completions', $requestBody);
        } catch (\Throwable $e) {
            return EnrichmentResult::fail($e->getMessage(), $model);
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (!$response->successful()) {
            $errorBody = mb_substr($response->body(), 0, 500);
            Log::warning('Inbox: OpenAI enrichment API error', [
                'status' => $response->status(),
                'model' => $model,
                'template_key' => $template->key,
                'body_preview' => $errorBody,
            ]);
            return EnrichmentResult::fail(
                'OpenAI API error ' . $response->status() . ': ' . $errorBody,
                $model,
            );
        }

        $data = $response->json();
        $content = (string) ($data['choices'][0]['message']['content'] ?? '');
        if ($content === '') {
            return EnrichmentResult::fail('Provider returned empty content.', $model);
        }

        $output = $this->parseJson($content);
        if ($output === null) {
            return EnrichmentResult::fail('Provider returned non-JSON content.', $model);
        }

        $usage = $data['usage'] ?? [];
        $tokensIn = (int) ($usage['prompt_tokens'] ?? 0);
        $tokensOut = (int) ($usage['completion_tokens'] ?? 0);

        return EnrichmentResult::ok(
            output: $output,
            providerModel: $model,
            tokensInput: $tokensIn ?: null,
            tokensOutput: $tokensOut ?: null,
            costMicroCents: $this->estimateCost($model, $tokensIn, $tokensOut),
            durationMs: $durationMs,
        );
    }

    protected function resolveApiKey(): ?string
    {
        $key = config('services.openai.api_key')
            ?? config('services.openai.key')
            ?? env('OPENAI_API_KEY');
        return (is_string($key) && $key !== '') ? $key : null;
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

    /**
     * Make a JSON Schema strict-mode compliant for OpenAI Structured Outputs.
     *
     * Strict mode requires, at every object level:
     *   - additionalProperties: false
     *   - all properties listed in `required`
     *
     * Optional fields are kept optional by widening their type to allow null
     * (so the model can return null instead of omitting). Arrays / nested
     * objects are processed recursively.
     *
     * Existing templates won't have been written with this in mind, so we
     * adapt at runtime rather than forcing every author to know the rules.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function adaptSchemaForStrict(array $schema): array
    {
        // Dispatch on structure, NOT on the (possibly widened) type string.
        // Once we mark an optional field nullable, its `type` becomes
        // ['array', 'null'] and a `$type === 'array'` guard silently stops
        // recursing into `items` — that leaves nested object schemas without
        // additionalProperties:false and OpenAI rejects the whole request.
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $schema['additionalProperties'] = false;

            $allProps = array_keys($schema['properties']);
            $required = $schema['required'] ?? [];
            $optional = array_values(array_diff($allProps, $required));

            foreach ($optional as $prop) {
                $propSchema = $schema['properties'][$prop];
                $propType = $propSchema['type'] ?? null;
                if (is_string($propType) && $propType !== 'null') {
                    $schema['properties'][$prop]['type'] = [$propType, 'null'];
                } elseif (is_array($propType) && !in_array('null', $propType, true)) {
                    $schema['properties'][$prop]['type'] = array_merge($propType, ['null']);
                }
            }
            $schema['required'] = $allProps;

            foreach ($schema['properties'] as $k => $v) {
                if (is_array($v)) {
                    $schema['properties'][$k] = $this->adaptSchemaForStrict($v);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->adaptSchemaForStrict($schema['items']);
        }

        return $schema;
    }
}
