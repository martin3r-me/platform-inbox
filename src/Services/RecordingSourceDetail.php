<?php

namespace Platform\Inbox\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Inbox\Contracts\SourceDetail;

/**
 * Live-Detail für den Aufnahme-Kanal: Transkript-Segmente + Dauer für den
 * Reading-Pane. Quellen-agnostisch — die Daten liegen beim Ingest schon auf dem
 * Inbox-Item (inbox_item_segments + audio_duration_seconds), egal ob die Aufnahme
 * von Plaud, Roh-Audio oder einem künftigen Anbieter via whisper kam.
 *
 * Registriert in der Core-SourceDetailRegistry; Core\StreamQuery::detailForItem
 * merged das für channel='recording' dazu.
 */
class RecordingSourceDetail implements SourceDetail
{
    public function channel(): string
    {
        return 'recording';
    }

    public function detailForItem(int $inboxItemId): ?array
    {
        if (! Schema::hasTable('inbox_items') || ! Schema::hasTable('inbox_item_segments')) {
            return null;
        }

        $item = DB::table('inbox_items')->where('id', $inboxItemId)->first();
        if (! $item || ($item->channel ?? null) !== 'recording') {
            return null;
        }

        // Speaker-Label → Anzeigename (aus Participants), sonst das Label selbst.
        $names = [];
        if (Schema::hasTable('inbox_item_participants')) {
            foreach (
                DB::table('inbox_item_participants')
                    ->where('inbox_item_id', $inboxItemId)
                    ->where('role', 'speaker')
                    ->get() as $p
            ) {
                if (! empty($p->identifier)) {
                    $names[$p->identifier] = $p->display_name ?: $p->identifier;
                }
            }
        }

        $segments = DB::table('inbox_item_segments')
            ->where('inbox_item_id', $inboxItemId)
            ->orderBy('start_seconds')
            ->get()
            ->map(fn ($s) => [
                $this->fmt((int) ($s->start_seconds ?? 0)),
                $names[$s->speaker_label] ?? ($s->speaker_label ?: 'Sprecher'),
                (string) ($s->text ?? ''),
            ])
            ->all();

        return [
            'channel'       => 'recording',
            'channel_label' => 'Aufnahme',
            'duration'      => $this->fmt((int) ($item->audio_duration_seconds ?? 0)),
            'segments'      => $segments,
            'actions'       => [],
        ];
    }

    protected function fmt(int $s): string
    {
        if ($s <= 0) {
            return '0:00';
        }
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
    }
}
