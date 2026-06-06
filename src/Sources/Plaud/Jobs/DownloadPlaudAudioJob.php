<?php

namespace Platform\Inbox\Sources\Plaud\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Platform\Core\Services\ContextFileService;
use Platform\Inbox\Models\InboxItem;
use Throwable;

/**
 * Pulls the original audio file from Plaud's signed URL, persists it
 * through ContextFileService onto the platform's default disk (S3 in
 * production), and adds an audio_original reference to the inbox item.
 *
 * Async because audio downloads from the Plaud cloud are size- and
 * latency-bound; the sync tool returns immediately and this fills in.
 *
 * Soft-coupled: if ContextFileService isn't available, or the URL has
 * gone stale, the job logs and exits — the item stays usable as
 * transcript-only.
 */
class DownloadPlaudAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(
        public int $inboxItemId,
        public string $sourceUrl,
    ) {}

    public function handle(): void
    {
        if (!class_exists(ContextFileService::class)) {
            return;
        }
        $item = InboxItem::find($this->inboxItemId);
        if (!$item) {
            return;
        }

        // Skip if we already have an audio_original reference attached
        // (idempotency for retries / re-syncs).
        $existing = $item->getOrderedFileReferences()
            ->first(fn ($r) => ($r->meta['kind'] ?? null) === 'audio_original');
        if ($existing) {
            return;
        }

        $tmpPath = null;
        try {
            $response = Http::timeout(60)->withOptions(['stream' => false])->get($this->sourceUrl);
            if (!$response->successful()) {
                Log::warning('Inbox.Plaud: audio download HTTP error', [
                    'item_id' => $item->id,
                    'status' => $response->status(),
                    'url' => $this->shortenForLog($this->sourceUrl),
                ]);
                return;
            }

            $body = $response->body();
            if ($body === '') {
                return;
            }

            $extension = $this->guessExtension($response->header('Content-Type'), $this->sourceUrl);
            $tmpPath = tempnam(sys_get_temp_dir(), 'plaud-audio-') . '.' . $extension;
            if (file_put_contents($tmpPath, $body) === false) {
                return;
            }

            $mime = @mime_content_type($tmpPath) ?: ($response->header('Content-Type') ?: 'audio/mpeg');
            $originalName = 'plaud-' . $item->uuid . '.' . $extension;

            $file = new UploadedFile($tmpPath, $originalName, $mime, null, true);

            $result = app(ContextFileService::class)->uploadForContext(
                file: $file,
                contextType: InboxItem::class,
                contextId: $item->id,
                options: [
                    'folder' => $item->audioFolder(),
                    'team_id' => $item->team_id,
                    'user_id' => $item->user_id,
                ],
            );

            if (!empty($result['context_file_id'])) {
                $item->addFileReference(
                    (int) $result['context_file_id'],
                    ['kind' => 'audio_original', 'persisted_by' => 'plaud:DownloadPlaudAudioJob'],
                );
            }
        } catch (Throwable $e) {
            Log::warning('Inbox.Plaud: audio download failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($tmpPath && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    protected function guessExtension(?string $contentType, string $url): string
    {
        // Prefer URL extension when Plaud serves typed URLs (.mp3, .wav, .m4a, .webm).
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
        if (in_array($ext, ['mp3', 'wav', 'm4a', 'ogg', 'opus', 'webm', 'aac', 'flac'], true)) {
            return $ext;
        }

        return match (true) {
            str_contains((string) $contentType, 'mpeg') => 'mp3',
            str_contains((string) $contentType, 'wav') => 'wav',
            str_contains((string) $contentType, 'mp4'), str_contains((string) $contentType, 'm4a') => 'm4a',
            str_contains((string) $contentType, 'ogg') => 'ogg',
            str_contains((string) $contentType, 'webm') => 'webm',
            default => 'audio',
        };
    }

    protected function shortenForLog(string $url): string
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        return $host . substr($path, 0, 60);
    }
}
