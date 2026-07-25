<?php

namespace Platform\Inbox\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Inbox\Contracts\InboxMailQueryContract;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;

/**
 * Mail-Query für die persönliche Sicht (home). Die LISTE kommt quellfrei aus
 * InboxItem-Feldern (robust). Das DETAIL zieht den vollen Thread (alle Mails
 * derselben conversation_id) + Org-Knoten-Kontext — loose über DB::table, kein
 * Hart-Bezug aufs user-connectors-Modell.
 */
class InboxMailQueryService implements InboxMailQueryContract
{
    public function listForUser(int $userId, int $teamId, int $limit = 25): array
    {
        $items = InboxItem::query()
            ->where('user_id', $userId)
            ->where('channel', Channel::Mail->value)
            ->where('status', InboxItemStatus::New->value)
            ->where(function ($q) {
                $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })
            ->orderByDesc('received_at')
            ->get();

        // Nach Thread gruppieren (conversation_id) — der Thread ist die Einheit.
        // Repräsentant = neueste Mail (Query ist desc → erstes Element der Gruppe).
        $groups = [];
        foreach ($items as $item) {
            $key = $item->conversation_id ?: 'single:' . $item->id;
            $groups[$key][] = $item;
        }

        $rows = [];
        foreach ($groups as $thread) {
            $rep = $thread[0];
            $start = $rep->received_at ? Carbon::parse($rep->received_at) : null;

            $rows[] = [
                'id'              => (int) $rep->id,
                'subject'         => $rep->subject ?: '(kein Betreff)',
                'sender'          => $rep->sender_label ?: ($rep->sender_identifier ?: 'Unbekannt'),
                'preview'         => $rep->preview,
                'time'            => $this->timeLabel($start),
                'unread'          => true, // Liste zieht nur 'new' → offene Threads
                'has_attachments' => false,
                'thread_count'    => count($thread),
                '_ts'             => $start ? $start->getTimestamp() : 0,
            ];
        }

        usort($rows, fn ($a, $b) => $b['_ts'] <=> $a['_ts']); // neuester Thread zuerst
        $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$r) {
            unset($r['_ts']);
        }

        return $rows;
    }

    public function detailForItem(int $inboxItemId): ?array
    {
        $item = InboxItem::find($inboxItemId);
        if (!$item || $item->channel?->value !== Channel::Mail->value) {
            return null;
        }

        $session = $this->loadSession($item);
        $thread = $this->thread($item, $session);

        return [
            'channel'         => 'mail',
            'channel_label'   => 'E-Mail',
            'subject'         => ($session->subject ?? null) ?: ($item->subject ?: '(kein Betreff)'),
            'sender'          => ($session->from_name ?? null) ?: ($session->from_address ?? $item->sender_label ?? 'Unbekannt'),
            'time'            => $this->timeLabel($item->received_at ? Carbon::parse($item->received_at) : null),
            'summary'         => null,
            'thread'          => $thread,
            'older'           => 0,
            'has_attachments' => (bool) ($session->has_attachments ?? false),
            'context'         => $this->entities($item),
            'related'         => null,
        ];
    }

    protected function loadSession(InboxItem $item)
    {
        if (
            ($item->source_type ?? null) === 'user_connector_mail_session'
            && Schema::hasTable('user_connector_mail_sessions')
        ) {
            return DB::table('user_connector_mail_sessions')->where('id', $item->source_id)->first();
        }

        return null;
    }

    /** Voller Verlauf: alle Mails derselben conversation_id, chronologisch. */
    protected function thread(InboxItem $item, $session): array
    {
        $convId = $session->conversation_id ?? null;

        $rows = collect();
        if ($convId && Schema::hasTable('user_connector_mail_sessions')) {
            try {
                $rows = DB::table('user_connector_mail_sessions')
                    ->where('conversation_id', $convId)
                    ->orderByRaw('COALESCE(received_at, sent_at) ASC')
                    ->limit(50)
                    ->get();
            } catch (\Throwable $e) {
                $rows = collect();
            }
        }

        // Fallback: nur die einzelne Nachricht.
        if ($rows->isEmpty()) {
            $rows = $session ? collect([$session]) : collect();
        }
        if ($rows->isEmpty()) {
            return [];
        }

        return $rows->map(function ($m) use ($item) {
            $when = $m->received_at ?? $m->sent_at ?? $item->received_at ?? null;

            return [
                'from' => ($m->from_name ?? null) ?: ($m->from_address ?? 'Unbekannt'),
                'time' => $this->timeLabel($when ? Carbon::parse($when) : null, true),
                'body' => $this->cleanBody(($m->body ?? null) ?: ($m->body_preview ?? '')),
                'me'   => ($m->direction ?? null) === 'outbound',
            ];
        })->values()->all();
    }

    /** HTML-Mails auf lesbaren Text reduzieren (kein roher Tag-Salat im Pane). */
    protected function cleanBody(?string $body): string
    {
        if (!$body) {
            return '';
        }
        // <br>/<p>/<div> → Zeilenumbruch, dann Tags weg, Entities dekodieren.
        $text = preg_replace('#<\s*(br|/p|/div|/tr)\s*/?>#i', "\n", $body);
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // überzählige Leerzeilen kollabieren.
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);

        return trim((string) $text);
    }

    protected function entities(InboxItem $item): array
    {
        try {
            $links = app(InboxEntityLinkService::class)->linksFor($item);
        } catch (\Throwable $e) {
            return [];
        }

        return collect($links)->map(fn ($e) => [
            'id'    => $e['id'] ?? null,
            'label' => $e['name'] ?? '—',
            'path'  => $e['path'] ?? null,
            'icon'  => 'heroicon-o-share',
        ])->all();
    }

    protected function timeLabel(?Carbon $when, bool $withDate = false): string
    {
        if (!$when) {
            return '—';
        }
        if ($when->isToday()) {
            return $when->format('H:i');
        }
        if ($when->isYesterday()) {
            return $withDate ? 'Gestern ' . $when->format('H:i') : 'Gestern';
        }
        if ($when->diffInDays(now()) < 7) {
            return $when->locale('de')->isoFormat($withDate ? 'dd H:i' : 'dd');
        }

        return $when->locale('de')->isoFormat($withDate ? 'D.M. H:i' : 'D.M.');
    }
}
