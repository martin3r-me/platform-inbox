<?php

namespace Platform\Inbox\Services;

use Platform\Core\Inbox\Contracts\RecordingIngestion;

/**
 * Core-RecordingIngestion-Naht, implementiert über den bestehenden
 * InboxAudioIngestionService (Item + Participants + Segmente + Enrichment-Dispatch
 * + LinkRules). Gibt die inbox_item-ID zurück, damit der Produzent (whisper) ohne
 * Inbox-Model auskommt.
 */
class RecordingIngestionService implements RecordingIngestion
{
    public function __construct(protected InboxAudioIngestionService $ingestion) {}

    public function ingest(array $payload): ?int
    {
        $item = $this->ingestion->ingest($payload);

        return $item?->id !== null ? (int) $item->id : null;
    }
}
