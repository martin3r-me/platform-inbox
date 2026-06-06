<?php

namespace Platform\Inbox\Sources\Plaud;

use Carbon\Carbon;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemEnrichment;
use Platform\Inbox\Models\InboxPlaudImport;
use Platform\Inbox\Services\InboxAudioIngestionService;

/**
 * Receives a complete Plaud recording payload (file_id, title, note markdown,
 * transcript segments, metadata) and routes it into the Inbox via the
 * standard audio-ingest contract. Plaud's own AI artefacts (summary,
 * action items, outline, AI suggestions) are persisted as a *secondary*
 * inbox_item_enrichment with provider="plaud:onboard" so the Inbox-native
 * enrichment (OpenAI/Claude) still runs and the user can compare.
 *
 * Idempotency: (team_id, plaud_file_id) — re-syncs return the existing item.
 */
class SyncTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.plaud.sync.POST';
    }

    public function getDescription(): string
    {
        return 'Importiert eine komplette Plaud-Aufnahme in einem Tool-Call in die Inbox. '
            . 'Erwartet: file_id (Dedup-Key), title, note_content (Markdown), segments (Array aus get_transcript), '
            . 'metadata (start_at, duration_ms, serial_number, optional source_url). '
            . 'Inbox legt ein recording-Item an, persistiert das Transkript + Segmente + Speaker, '
            . 'übernimmt Plaud-eigene Auswertungen als sekundäre Anreicherung und triggert dann automatisch die Inbox-Standard-Anreicherung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_id' => ['type' => 'string', 'description' => 'Plaud file ID — dient als Dedup-Key.'],
                'title' => ['type' => 'string', 'description' => 'Titel der Aufnahme.'],
                'note_content' => ['type' => 'string', 'description' => 'Markdown-Inhalt aus Plaud get_note.'],
                'segments' => [
                    'type' => 'array',
                    'description' => 'Transcript-Segmente aus get_transcript.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'content' => ['type' => 'string'],
                            'start_time' => ['type' => 'integer', 'description' => 'ms'],
                            'end_time' => ['type' => 'integer', 'description' => 'ms'],
                            'speaker' => ['type' => 'string', 'description' => 'Display-Name'],
                            'original_speaker' => ['type' => 'string', 'description' => 'Plaud-internes Label (Speaker 1, Speaker 2)'],
                            'embeddingKey' => ['type' => 'string', 'description' => 'Plaud Voice-UUID'],
                        ],
                    ],
                ],
                'metadata' => [
                    'type' => 'object',
                    'properties' => [
                        'serial_number' => ['type' => 'string'],
                        'start_at' => ['type' => 'string', 'description' => 'ISO-8601'],
                        'duration_ms' => ['type' => 'integer'],
                        'source_url' => ['type' => 'string'],
                    ],
                ],
                'language' => ['type' => 'string', 'description' => 'Optional: ISO-Sprachcode. Default: "de".'],
            ],
            'required' => ['file_id', 'title', 'note_content', 'segments', 'metadata'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }
        $teamId = $context->team?->id ?? $context->user->currentTeam?->id;
        if (!$teamId) {
            return ToolResult::error('AUTH_ERROR', 'Kein Team im Kontext.');
        }

        $fileId = trim((string) ($arguments['file_id'] ?? ''));
        $title = trim((string) ($arguments['title'] ?? ''));
        $noteContent = (string) ($arguments['note_content'] ?? '');
        $segments = $arguments['segments'] ?? [];
        $metadata = $arguments['metadata'] ?? [];

        if ($fileId === '' || $title === '' || empty($segments)) {
            return ToolResult::error('VALIDATION_ERROR', 'file_id, title und segments sind erforderlich.');
        }

        // Idempotency: re-sync of the same Plaud file returns the existing item.
        $existing = InboxPlaudImport::query()
            ->where('team_id', $teamId)
            ->where('plaud_file_id', $fileId)
            ->first();
        if ($existing) {
            return ToolResult::success([
                'inbox_item_id' => $existing->inbox_item_id,
                'plaud_file_id' => $fileId,
                'duplicate' => true,
                'message' => "Bereits importiert als Inbox-Item #{$existing->inbox_item_id}.",
            ]);
        }

        // Parse the Plaud note + build the standard audio payload.
        $parsed = (new PlaudNoteParser())->parse($noteContent);
        [$bodyText, $speakerList, $segmentList] = $this->buildContract($segments);

        $recordedAt = $this->parseTimestamp($metadata['start_at'] ?? $metadata['recorded_at'] ?? null);
        $durationSeconds = isset($metadata['duration_ms']) ? (int) round(((int) $metadata['duration_ms']) / 1000) : null;
        $deviceSerial = isset($metadata['serial_number']) ? trim((string) $metadata['serial_number']) : null;
        $sourceUrl = isset($metadata['source_url']) ? trim((string) $metadata['source_url']) : null;

        $payload = [
            'team_id' => $teamId,
            'user_id' => $context->user->id,
            'source_type' => 'plaud_recording',
            'source_id' => crc32($fileId),  // deterministic int from string id; the real key lives in inbox_plaud_imports
            'title' => $title,
            'body' => $bodyText,
            'language' => $arguments['language'] ?? 'de',
            'audio_duration_seconds' => $durationSeconds,
            'audio_recorded_at' => $recordedAt,
            'speakers' => $speakerList,
            'segments' => $segmentList,
            // Plaud doesn't hand us the raw audio here. A later patch can
            // download metadata.source_url and persist via ContextFileService.
            'audio_file' => null,
        ];

        try {
            $item = app(InboxAudioIngestionService::class)->ingest($payload);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Inbox-Ingest fehlgeschlagen: ' . $e->getMessage());
        }

        if (!$item) {
            return ToolResult::error('EXECUTION_ERROR', 'Inbox-Item konnte nicht angelegt werden.');
        }

        // Track this import for idempotency and forensics.
        InboxPlaudImport::create([
            'team_id' => $teamId,
            'plaud_file_id' => $fileId,
            'inbox_item_id' => $item->id,
            'device_serial' => $deviceSerial,
            'source_url' => $sourceUrl,
            'plaud_recorded_at' => $recordedAt,
        ]);

        // Persist Plaud's own analysis as a *secondary* enrichment.
        // is_primary stays false; the OpenAI-driven meeting-transcript template
        // dispatched by InboxAudioIngestionService becomes primary on success.
        $this->storeVendorEnrichment($item, $parsed);

        return ToolResult::success([
            'inbox_item_id' => $item->id,
            'plaud_file_id' => $fileId,
            'duplicate' => false,
            'segments_count' => count($segmentList),
            'speakers_count' => count($speakerList),
            'parsed_sections' => [
                'summary' => $parsed['summary'] !== null,
                'action_items' => $parsed['action_items'] !== null,
                'ai_suggestions' => $parsed['ai_suggestions'] !== null,
                'outline' => $parsed['outline'] !== null,
            ],
            'message' => 'Plaud-Aufnahme in Inbox importiert.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'plaud', 'sync', 'import', 'audio'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }

    /**
     * Converts Plaud's segment array into:
     *   - $bodyText:    "Karen: …\n\nKlaus: …"  for the LLM and the raw view
     *   - $speakerList: [{label, display_name}]   for participants
     *   - $segmentList: [{start_seconds,end_seconds,speaker_label,text}]
     * Speaker labels are stable A,B,C,… per (original_speaker | embeddingKey).
     */
    protected function buildContract(array $rawSegments): array
    {
        $labelMap = [];
        $labels = [];
        $labelIndex = 0;
        $assignLabel = function (string $key) use (&$labelMap, &$labels, &$labelIndex) {
            if ($key === '') {
                $key = 'unknown';
            }
            if (!isset($labelMap[$key])) {
                $labelMap[$key] = chr(65 + $labelIndex);
                $labels[$labelMap[$key]] = $key;
                $labelIndex++;
            }
            return $labelMap[$key];
        };

        $speakerNames = [];
        $segments = [];
        $bodyLines = [];

        foreach ($rawSegments as $raw) {
            $original = trim((string) ($raw['original_speaker'] ?? ''));
            $embeddingKey = trim((string) ($raw['embeddingKey'] ?? ''));
            $speakerName = trim((string) ($raw['speaker'] ?? ''));
            $text = trim((string) ($raw['content'] ?? $raw['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $startMs = (int) ($raw['start_time'] ?? 0);
            $endMs = (int) ($raw['end_time'] ?? 0);

            $clusterKey = $embeddingKey !== '' ? $embeddingKey : ($original !== '' ? $original : $speakerName);
            $label = $assignLabel($clusterKey);
            $speakerNames[$label] = $speakerName !== '' ? $speakerName : ($original !== '' ? $original : $label);

            $segments[] = [
                'start_seconds' => (int) round($startMs / 1000),
                'end_seconds' => (int) round($endMs / 1000),
                'speaker_label' => $label,
                'text' => $text,
            ];
            $bodyLines[] = ($speakerNames[$label] ?? $label) . ': ' . $text;
        }

        $speakers = [];
        foreach ($speakerNames as $label => $displayName) {
            $speakers[] = ['label' => $label, 'display_name' => $displayName];
        }

        return [implode("\n\n", $bodyLines), $speakers, $segments];
    }

    protected function parseTimestamp(mixed $value): ?Carbon
    {
        if (!$value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function storeVendorEnrichment(InboxItem $item, array $parsed): void
    {
        $output = array_filter([
            'summary' => $parsed['summary'] ?? null,
            'action_items_raw' => $parsed['action_items'] ?? null,
            'ai_suggestions' => $parsed['ai_suggestions'] ?? null,
            'outline' => $parsed['outline'] ?? null,
        ], fn ($v) => $v !== null);

        if (empty($output)) {
            return;
        }

        InboxItemEnrichment::create([
            'inbox_item_id' => $item->id,
            'template_id' => null,
            'template_key' => 'plaud-onboard',
            'template_version' => 1,
            'status' => InboxItemEnrichment::STATUS_DONE,
            'provider' => 'plaud:onboard',
            'provider_model' => 'plaud-builtin',
            'output' => $output,
            'is_primary' => false,
            'run_at' => now(),
        ]);
    }
}
