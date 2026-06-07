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

            // Prefer a full body column on the source session if exposed; otherwise
            // fall back to the preview field. Long-term, source modules should
            // persist the full content; the Inbox always treats body as the
            // canonical input for downstream enrichment.
            $preview = $cfg['preview_field'] ? ($session[$cfg['preview_field']] ?? null) : null;
            $body = ($cfg['body_field'] ?? null)
                ? ($session[$cfg['body_field']] ?? $preview)
                : $preview;

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
                'preview' => $preview,
                'body' => $body,
                'body_format' => $cfg['body_format'] ?? 'text',
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
        $this->applyRulesForInserts($sourceMorph, $inserts);
        $this->createParticipantsForInserts($sourceMorph, $inserts);
        $this->dispatchDefaultEnrichmentForInserts($sourceMorph, $inserts);

        return count($inserts);
    }

    /**
     * Materialize InboxItemParticipants for each freshly inserted item:
     * sender + recipients (mail), caller/callee (call/message), organizer +
     * attendees (meeting). Entity-matching is best-effort via existing
     * sender_subscriptions or user email lookup; ambiguity stays nullable.
     */
    protected function createParticipantsForInserts(string $sourceMorph, array $inserts): void
    {
        if (empty($inserts)) {
            return;
        }

        $sourceIds = array_unique(array_map(fn ($r) => (int) $r['source_id'], $inserts));
        $items = \Platform\Inbox\Models\InboxItem::query()
            ->where('source_type', $sourceMorph)
            ->whereIn('source_id', $sourceIds)
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $sessions = $this->fetchSessionFieldsForParticipants($sourceMorph, $sourceIds);

        $participantRows = [];
        $now = now();
        foreach ($items as $item) {
            $session = $sessions[$item->source_id] ?? null;
            if (!$session) {
                continue;
            }
            $direction = $session->direction ?? 'inbound';

            foreach ($this->extractParticipantsFromSession($sourceMorph, $session, $direction) as $p) {
                $participantRows[] = array_merge($p, [
                    'inbox_item_id' => $item->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (!empty($participantRows)) {
            foreach (array_chunk($participantRows, 500) as $chunk) {
                DB::table('inbox_item_participants')->insert($chunk);
            }
        }
    }

    /**
     * Fetch only the fields needed for participant extraction. Keeps the
     * earlier (subject/body) fetch separate so it doesn't grow unboundedly.
     */
    protected function fetchSessionFieldsForParticipants(string $sourceMorph, array $ids): array
    {
        $sessionTable = $this->tableForMorph($sourceMorph);
        if ($sessionTable === null) {
            return [];
        }

        $columns = match ($sourceMorph) {
            'user_connector_mail_session' => ['id', 'direction', 'from_address', 'from_name', 'to_addresses', 'cc_addresses'],
            'user_connector_call_session' => ['id', 'direction', 'from_number', 'to_number'],
            'user_connector_message_session' => ['id', 'direction', 'from_identifier', 'to_identifier', 'from_user_id'],
            'user_connector_meeting_session' => ['id', 'direction', 'organizer_address', 'organizer_name', 'attendee_addresses'],
            default => null,
        };
        if (!$columns) {
            return [];
        }

        return DB::table($sessionTable)
            ->whereIn('id', $ids)
            ->select($columns)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * Returns the participant rows for one session, role-tagged.
     * @return array<int, array<string, mixed>>
     */
    protected function extractParticipantsFromSession(string $sourceMorph, object $session, string $direction): array
    {
        $rows = [];

        switch ($sourceMorph) {
            case 'user_connector_mail_session':
                if ($from = $session->from_address ?? null) {
                    $rows[] = $this->participantRow(
                        role: \Platform\Inbox\Models\InboxItemParticipant::ROLE_SENDER,
                        identifier: $from,
                        kind: 'email',
                        displayName: $session->from_name ?? null,
                    );
                }
                foreach ($this->splitAddresses($session->to_addresses ?? null) as $address) {
                    $rows[] = $this->participantRow(
                        role: \Platform\Inbox\Models\InboxItemParticipant::ROLE_RECIPIENT,
                        identifier: $address,
                        kind: 'email',
                    );
                }
                foreach ($this->splitAddresses($session->cc_addresses ?? null) as $address) {
                    $rows[] = $this->participantRow(
                        role: \Platform\Inbox\Models\InboxItemParticipant::ROLE_CC,
                        identifier: $address,
                        kind: 'email',
                    );
                }
                break;

            case 'user_connector_call_session':
                $callerNumber = $direction === 'inbound' ? ($session->from_number ?? null) : ($session->to_number ?? null);
                if ($callerNumber) {
                    $rows[] = $this->participantRow(
                        role: \Platform\Inbox\Models\InboxItemParticipant::ROLE_SENDER,
                        identifier: $callerNumber,
                        kind: 'phone',
                    );
                }
                break;

            case 'user_connector_message_session':
                $from = $direction === 'inbound' ? ($session->from_identifier ?? null) : ($session->to_identifier ?? null);
                if ($from) {
                    $rows[] = $this->participantRow(
                        role: \Platform\Inbox\Models\InboxItemParticipant::ROLE_SENDER,
                        identifier: $from,
                        kind: 'teams',
                    );
                }
                break;

            case 'user_connector_meeting_session':
                if ($organizer = $session->organizer_address ?? null) {
                    $rows[] = $this->participantRow(
                        role: \Platform\Inbox\Models\InboxItemParticipant::ROLE_ORGANIZER,
                        identifier: $organizer,
                        kind: 'email',
                        displayName: $session->organizer_name ?? null,
                    );
                }
                foreach ($this->splitAddresses($session->attendee_addresses ?? null) as $address) {
                    $rows[] = $this->participantRow(
                        role: \Platform\Inbox\Models\InboxItemParticipant::ROLE_ATTENDEE,
                        identifier: $address,
                        kind: 'email',
                    );
                }
                break;
        }

        return $rows;
    }

    /**
     * Splits a "Kommasepariert"-style address field (text column in
     * user_connector_* sessions) into a clean list of trimmed addresses.
     * Defensive — accepts comma- or semicolon-separated input, drops
     * empty entries.
     *
     * @return array<int, string>
     */
    protected function splitAddresses(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }
        $parts = preg_split('/[,;]+/', $trimmed) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }

    protected function participantRow(string $role, ?string $identifier, ?string $kind, ?string $displayName = null): array
    {
        $normalized = InboxSenderSubscription::normalize($identifier, $kind ?? 'email');
        return [
            'role' => $role,
            'identifier' => $normalized,
            'identifier_kind' => $kind,
            'display_name' => $displayName,
            'entity_id' => $this->resolveEntityIdForIdentifier($normalized, $kind),
            'entity_confidence' => null,
            'voice_profile_id' => null,
        ];
    }

    /**
     * Cheapest-possible entity resolution: only emails, and only via the
     * "user email matches organization_entity.linked_user_id" path. Avoids
     * touching organization classes directly so the soft-coupling is kept.
     */
    protected function resolveEntityIdForIdentifier(?string $identifier, ?string $kind): ?int
    {
        if ($kind !== 'email' || !$identifier) {
            return null;
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('organization_entities')) {
            return null;
        }
        return DB::table('organization_entities as e')
            ->join('users as u', 'u.id', '=', 'e.linked_user_id')
            ->whereRaw('LOWER(u.email) = ?', [strtolower($identifier)])
            ->whereNull('e.deleted_at')
            ->where('e.is_active', true)
            ->value('e.id');
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

    /**
     * For every freshly created item, look up the default enrichment template
     * for its channel (team-specific overrides global) and dispatch the
     * RunEnrichmentJob. Items without a template just stay un-enriched.
     */
    protected function dispatchDefaultEnrichmentForInserts(string $sourceMorph, array $inserts): void
    {
        if (empty($inserts)) {
            return;
        }

        $sourceIds = array_unique(array_map(fn ($r) => (int) $r['source_id'], $inserts));

        $items = \Platform\Inbox\Models\InboxItem::query()
            ->where('source_type', $sourceMorph)
            ->whereIn('source_id', $sourceIds)
            ->get();

        // Cache templates per (channel, team) so we don't hammer the DB.
        $templateCache = [];

        foreach ($items as $item) {
            $channel = $item->channel?->value;
            if (!$channel) {
                continue;
            }
            $cacheKey = $channel . '|' . ($item->team_id ?? 0);
            if (!array_key_exists($cacheKey, $templateCache)) {
                $templateCache[$cacheKey] = \Platform\Inbox\Models\InboxEnrichmentTemplate::defaultForChannel($channel, $item->team_id);
            }
            $template = $templateCache[$cacheKey];
            if (!$template) {
                continue;
            }
            try {
                \Platform\Inbox\Jobs\RunEnrichmentJob::dispatch($item->id, $template->id);
            } catch (\Throwable $e) {
                \Log::warning('Inbox: enrichment dispatch failed', [
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * For every freshly created item, run the rule engine — auto-link to
     * matching entities and record audit events.
     */
    protected function applyRulesForInserts(string $sourceMorph, array $inserts): void
    {
        if (empty($inserts)) {
            return;
        }

        $sourceIds = array_unique(array_map(fn ($r) => (int) $r['source_id'], $inserts));

        $items = \Platform\Inbox\Models\InboxItem::query()
            ->where('source_type', $sourceMorph)
            ->whereIn('source_id', $sourceIds)
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $engine = app(\Platform\Inbox\Services\InboxRuleEngine::class);
        foreach ($items as $item) {
            try {
                $engine->applyRulesTo($item);
            } catch (\Throwable $e) {
                \Log::warning('Inbox: rule application failed', [
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
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
            $cfg['body_field'] ?? null,
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
}
