<?php

namespace Platform\Inbox\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Enums\SubscriptionStatus;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxSenderSubscription;

/**
 * Materializes user-connector session rows into per-user inbox_items.
 *
 * Runs on a schedule (e.g. every 5 minutes) — picks up the most recent
 * sessions per connection and inserts inbox_items for sessions that
 * don't have one yet. The subscribed/unsubscribed/muted status of the
 * sender determines whether the item is created at all, or created
 * with status=ignored.
 */
class InboxIngestionService
{
    public function ingestRecent(int $sinceMinutes = 60): int
    {
        // user-connectors not installed → nothing to ingest, no error.
        if (!Schema::hasTable('user_connector_connections')) {
            return 0;
        }

        $since = CarbonImmutable::now()->subMinutes($sinceMinutes);
        $sources = config('inbox.sources', []);

        $created = 0;
        foreach ($sources as $morph => $cfg) {
            $created += $this->ingestSource($morph, $cfg, $since);
        }

        return $created;
    }

    protected function ingestSource(string $sourceMorph, array $cfg, CarbonImmutable $since): int
    {
        $sessionTable = $this->tableForMorph($sourceMorph);
        if ($sessionTable === null || !Schema::hasTable($sessionTable)) {
            return 0;
        }

        $receivedField = $cfg['received_at_field'];

        $rows = DB::table($sessionTable . ' as s')
            ->join('user_connector_connections as c', 'c.id', '=', 's.connection_id')
            ->where("s.{$receivedField}", '>=', $since)
            ->whereNotExists(function ($q) use ($sourceMorph) {
                $q->select(DB::raw(1))
                    ->from('inbox_items')
                    ->whereColumn('inbox_items.source_id', 's.id')
                    ->where('inbox_items.source_type', $sourceMorph);
            })
            ->select([
                's.id as session_id',
                's.connection_id',
                'c.owner_user_id',
                "s.{$receivedField} as received_at",
                's.direction',
            ])
            ->limit(2000)
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $sessionIds = $rows->pluck('session_id')->all();
        $sessionData = $this->fetchSessionFields($sessionTable, $sessionIds, $cfg);

        $userTeamCache = [];
        $inserts = [];

        foreach ($rows as $row) {
            $session = $sessionData[$row->session_id] ?? null;
            if ($session === null) {
                continue;
            }

            $teamId = $userTeamCache[$row->owner_user_id] ??= $this->resolveTeamId($row->owner_user_id);
            if ($teamId === null) {
                continue;
            }

            $senderIdentifier = $this->normalizeSender(
                $session[$cfg['sender_field']] ?? null,
                $cfg['sender_kind']
            );

            $status = $this->statusFromSubscription(
                $row->owner_user_id,
                $cfg['sender_kind'],
                $senderIdentifier
            );

            if ($status === null) {
                // Unsubscribed — skip entirely.
                continue;
            }

            $inserts[] = [
                'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                'team_id' => $teamId,
                'user_id' => $row->owner_user_id,
                'source_type' => $sourceMorph,
                'source_id' => $row->session_id,
                'channel' => $cfg['channel'],
                'sender_identifier' => $senderIdentifier,
                'sender_kind' => $cfg['sender_kind'],
                'sender_label' => $session['_sender_label'] ?? null,
                'subject' => $cfg['subject_field'] ? ($session[$cfg['subject_field']] ?? null) : null,
                'preview' => $cfg['preview_field'] ? ($session[$cfg['preview_field']] ?? null) : null,
                'direction' => $row->direction,
                'status' => $status->value,
                'received_at' => $row->received_at,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($inserts)) {
            return 0;
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('inbox_items')->insert($chunk);
        }

        $this->upsertSubscriptionsForInserts($inserts);

        return count($inserts);
    }

    /**
     * For every freshly ingested item, ensure a subscription row exists for
     * (user, sender) — refresh label + last_seen_at, but never touch status
     * or is_vip on conflict (the user owns those).
     */
    protected function upsertSubscriptionsForInserts(array $inserts): void
    {
        $rows = [];
        $seen = [];

        foreach ($inserts as $i) {
            if (empty($i['sender_identifier']) || empty($i['sender_kind'])) {
                continue;
            }
            $key = $i['user_id'] . '|' . $i['sender_kind'] . '|' . $i['sender_identifier'];
            // Keep newest only — last write wins for duplicates in this chunk.
            if (isset($seen[$key]) && $seen[$key] >= $i['received_at']) {
                continue;
            }
            $seen[$key] = $i['received_at'];

            $rows[$key] = [
                'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                'team_id' => $i['team_id'],
                'user_id' => $i['user_id'],
                'sender_kind' => $i['sender_kind'],
                'sender_identifier' => $i['sender_identifier'],
                'status' => 'subscribed',
                'is_vip' => false,
                'label' => $i['sender_label'],
                'last_seen_at' => $i['received_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($rows)) {
            return;
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('inbox_sender_subscriptions')->upsert(
                $chunk,
                ['user_id', 'sender_kind', 'sender_identifier'],
                ['label', 'last_seen_at', 'updated_at'],
            );
        }
    }

    protected function fetchSessionFields(string $sessionTable, array $ids, array $cfg): array
    {
        $fields = array_filter([
            'id',
            $cfg['sender_field'],
            $cfg['subject_field'] ?? null,
            $cfg['preview_field'] ?? null,
        ]);

        // Mail has a separate from_name; everything else doesn't.
        if ($sessionTable === 'user_connector_mail_sessions') {
            $fields[] = 'from_name';
        }
        if ($sessionTable === 'user_connector_meeting_sessions') {
            $fields[] = 'organizer_name';
        }

        $rows = DB::table($sessionTable)
            ->whereIn('id', $ids)
            ->select(array_values(array_unique($fields)))
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $arr = (array) $r;
            $arr['_sender_label'] = $arr['from_name'] ?? $arr['organizer_name'] ?? null;
            $result[$r->id] = $arr;
        }

        return $result;
    }

    protected function statusFromSubscription(int $userId, string $kind, ?string $identifier): ?InboxItemStatus
    {
        if ($identifier === null) {
            return InboxItemStatus::New;
        }

        $sub = InboxSenderSubscription::query()
            ->where('user_id', $userId)
            ->where('sender_kind', $kind)
            ->where('sender_identifier', $identifier)
            ->first();

        if ($sub === null) {
            return InboxItemStatus::New;
        }

        return match ($sub->status) {
            SubscriptionStatus::Unsubscribed => null,
            SubscriptionStatus::Muted => InboxItemStatus::Ignored,
            SubscriptionStatus::Subscribed => InboxItemStatus::New,
        };
    }

    protected function resolveTeamId(int $userId): ?int
    {
        $teamId = DB::table('users')->where('id', $userId)->value('current_team_id');
        return $teamId !== null ? (int) $teamId : null;
    }

    protected function normalizeSender(?string $value, string $kind): ?string
    {
        return \Platform\Inbox\Models\InboxSenderSubscription::normalize($value, $kind);
    }

    protected function tableForMorph(string $morph): ?string
    {
        return match ($morph) {
            'user_connector_mail_session' => 'user_connector_mail_sessions',
            'user_connector_call_session' => 'user_connector_call_sessions',
            'user_connector_message_session' => 'user_connector_message_sessions',
            'user_connector_meeting_session' => 'user_connector_meeting_sessions',
            default => null,
        };
    }
}
