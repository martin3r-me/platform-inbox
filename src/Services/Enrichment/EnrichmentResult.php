<?php

namespace Platform\Inbox\Services\Enrichment;

/**
 * Structured result of one enrichment provider call. Stays a plain DTO
 * so providers can be swapped without touching the consumer.
 */
class EnrichmentResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly array $output,           // matches the template's output_schema
        public readonly string $providerModel,   // e.g. "gpt-4o-mini"
        public readonly ?int $tokensInput = null,
        public readonly ?int $tokensOutput = null,
        public readonly ?int $costMicroCents = null,
        public readonly ?int $durationMs = null,
        public readonly ?float $confidence = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function ok(
        array $output,
        string $providerModel,
        ?int $tokensInput = null,
        ?int $tokensOutput = null,
        ?int $costMicroCents = null,
        ?int $durationMs = null,
        ?float $confidence = null,
    ): self {
        return new self(
            ok: true,
            output: $output,
            providerModel: $providerModel,
            tokensInput: $tokensInput,
            tokensOutput: $tokensOutput,
            costMicroCents: $costMicroCents,
            durationMs: $durationMs,
            confidence: $confidence,
        );
    }

    public static function fail(string $message, string $providerModel = ''): self
    {
        return new self(
            ok: false,
            output: [],
            providerModel: $providerModel,
            errorMessage: $message,
        );
    }
}
