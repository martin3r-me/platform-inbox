<?php

namespace Platform\Inbox\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Platform\Core\Services\ContextFileService;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Jobs\RunEnrichmentJob;
use Platform\Inbox\Models\InboxEnrichmentTemplate;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemParticipant;
use Platform\Inbox\Models\InboxItemSegment;
use Platform\Inbox\Models\InboxSenderSubscription;
use Platform\Inbox\Models\InboxVoiceProfile;
use Symfony\Component\Uid\UuidV7;

/**
 * Public API for audio-source modules (Whisper, Plaud, future Twilio/Zoom
 * voicemail …) to drop a finished transcription into the Inbox.
 *
 * Contract — one item per recording, source-agnostic:
 *   - source_type / source_id  → backlink to producer's row
 *   - body = transcript (full text)
 *   - body_format = 'transcript'
 *   - segments[]               → InboxItemSegment rows
 *   - speakers[]               → InboxItemParticipant rows with role=speaker
 *   - audio_file (UploadedFile or array{path,disk,mime,size,original_name})
 *                              → ContextFileReference under inbox/audio/...
 *
 * Triggers the default-for-channel enrichment template automatically.
 */
class InboxAudioIngestionService
{
    public function ingest(array $payload): ?InboxItem
    {
        $required = ['team_id', 'user_id', 'source_type', 'source_id', 'body'];
        foreach ($required as $field) {
            if (!isset($payload[$field])) {
                throw new \InvalidArgumentException("InboxAudioIngestion: missing '{$field}'.");
            }
        }

        // Idempotency — if an item for this source already exists, return it.
        $existing = InboxItem::query()
            ->where('source_type', $payload['source_type'])
            ->where('source_id', $payload['source_id'])
            ->first();
        if ($existing) {
            return $existing;
        }

        $item = DB::transaction(function () use ($payload) {
            $now = now();
            $item = InboxItem::create([
                'uuid' => (string) UuidV7::generate(),
                'team_id' => (int) $payload['team_id'],
                'user_id' => (int) $payload['user_id'],
                'source_type' => (string) $payload['source_type'],
                'source_id' => (int) $payload['source_id'],
                'channel' => Channel::Recording->value,
                'subject' => $payload['title'] ?? $payload['subject'] ?? null,
                'preview' => $this->preview($payload['body']),
                'body' => $payload['body'],
                'body_format' => 'transcript',
                'language' => $payload['language'] ?? null,
                'audio_duration_seconds' => isset($payload['audio_duration_seconds'])
                    ? (int) $payload['audio_duration_seconds']
                    : null,
                'audio_recorded_at' => $payload['audio_recorded_at'] ?? null,
                'direction' => 'inbound',
                'status' => InboxItemStatus::New->value,
                'received_at' => $payload['audio_recorded_at'] ?? $now,
            ]);

            // Speakers → participants. Source-internal speaker_label is stored
            // as identifier with identifier_kind=speaker so segments can join
            // back to participants.
            foreach (($payload['speakers'] ?? []) as $speaker) {
                if (!isset($speaker['label']) && !isset($speaker['identifier'])) {
                    continue;
                }
                $label = (string) ($speaker['label'] ?? $speaker['identifier']);
                $displayName = $speaker['display_name'] ?? null;
                $entityId = isset($speaker['entity_id']) ? (int) $speaker['entity_id'] : null;
                $voiceProfile = $this->matchVoiceProfile($item->team_id, $entityId, $displayName);

                InboxItemParticipant::create([
                    'inbox_item_id' => $item->id,
                    'role' => InboxItemParticipant::ROLE_SPEAKER,
                    'identifier' => $label,
                    'identifier_kind' => 'speaker',
                    'display_name' => $displayName,
                    'entity_id' => $entityId ?: ($voiceProfile?->entity_id),
                    'entity_confidence' => $entityId ? 'high' : ($voiceProfile ? 'medium' : null),
                    'voice_profile_id' => $voiceProfile?->id,
                ]);

                if ($voiceProfile) {
                    $voiceProfile->update(['last_seen_at' => $now]);
                }
            }

            // Segments.
            $bulkSegments = [];
            foreach (($payload['segments'] ?? []) as $segment) {
                if (!isset($segment['text'])) {
                    continue;
                }
                $bulkSegments[] = [
                    'inbox_item_id' => $item->id,
                    'start_seconds' => (int) ($segment['start_seconds'] ?? 0),
                    'end_seconds' => (int) ($segment['end_seconds'] ?? 0),
                    'speaker_label' => isset($segment['speaker_label']) ? (string) $segment['speaker_label'] : null,
                    'text' => (string) $segment['text'],
                    'confidence' => isset($segment['confidence']) ? (float) $segment['confidence'] : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (!empty($bulkSegments)) {
                foreach (array_chunk($bulkSegments, 500) as $chunk) {
                    DB::table('inbox_item_segments')->insert($chunk);
                }
            }

            return $item;
        });

        // Sender-subscription bookkeeping isn't relevant for audio recordings
        // (there is no "sender" in the mail sense), but skipping is fine —
        // subscriptions are per-channel and audio doesn't need them.

        $this->attachAudioFile($item, $payload['audio_file'] ?? null);
        $this->dispatchDefaultEnrichment($item);

        return $item;
    }

    protected function attachAudioFile(InboxItem $item, mixed $audioFile): void
    {
        if (!$audioFile || !class_exists(ContextFileService::class)) {
            return;
        }
        try {
            $service = app(ContextFileService::class);
            $folder = $item->audioFolder();

            if ($audioFile instanceof UploadedFile) {
                $result = $service->uploadForContext(
                    file: $audioFile,
                    contextType: \Platform\Inbox\Models\InboxItem::class,
                    contextId: $item->id,
                    options: [
                        'folder' => $folder,
                        'team_id' => $item->team_id,
                        'user_id' => $item->user_id,
                    ],
                );
                if (isset($result['context_file_id'])) {
                    $item->addFileReference($result['context_file_id'], ['kind' => 'audio_original']);
                }
                return;
            }

            // Pre-uploaded file path → just register a reference. Producer is
            // responsible for storing the actual bytes on the configured disk.
            // We keep this branch minimal; a fuller flow (download from remote
            // URL, store on disk, then reference) lives in the producer.
            if (is_array($audioFile) && !empty($audioFile['context_file_id'])) {
                $item->addFileReference((int) $audioFile['context_file_id'], ['kind' => 'audio_original']);
            }
        } catch (\Throwable $e) {
            \Log::warning('Inbox: audio file attach failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function matchVoiceProfile(int $teamId, ?int $entityId, ?string $displayName): ?InboxVoiceProfile
    {
        return InboxVoiceProfile::findForTeam($teamId, $entityId, $displayName);
    }

    protected function dispatchDefaultEnrichment(InboxItem $item): void
    {
        $channel = $item->channel?->value;
        if (!$channel) {
            return;
        }
        $template = InboxEnrichmentTemplate::defaultForChannel($channel, $item->team_id);
        if (!$template) {
            return;
        }
        try {
            RunEnrichmentJob::dispatch($item->id, $template->id);
        } catch (\Throwable $e) {
            \Log::warning('Inbox: audio enrichment dispatch failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function preview(?string $body): ?string
    {
        if (!$body) {
            return null;
        }
        $stripped = preg_replace("/[ \t]+/", ' ', trim($body));
        return mb_substr($stripped, 0, 500);
    }
}
