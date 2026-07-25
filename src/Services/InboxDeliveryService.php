<?php

namespace Platform\Inbox\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Platform\Core\Models\User;
use Platform\Inbox\Contracts\InboxDelivery;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxItem;

/**
 * Standard-Implementierung des Push-Kontrakts: interne Ereignisse landen als
 * saubere InboxItems im Eingang des Users (Kanal 'system', Status 'new').
 *
 * Bewusst schlank: kein Connector-Kram, keine Audio-/Session-Logik. Anreicherung
 * und Scoring bleiben der Connector-Ingestion vorbehalten — System-Items sind
 * bereits strukturiert.
 */
class InboxDeliveryService implements InboxDelivery
{
    public function deliver(array $payload): ?InboxItem
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $teamId = (int) ($payload['team_id'] ?? 0);
        $subject = trim((string) ($payload['subject'] ?? ''));

        if (!$userId || !$teamId || $subject === '') {
            return null;
        }

        [$sourceType, $sourceId] = $this->resolveSource($payload, $userId);

        // Optionale Deduplizierung: ein Item je (User, Quelle).
        if (!empty($payload['dedupe'])) {
            $existing = InboxItem::query()
                ->where('user_id', $userId)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $body = $payload['body'] ?? null;
        $receivedAt = isset($payload['received_at'])
            ? Carbon::parse($payload['received_at'])
            : now();

        return InboxItem::create([
            'team_id'           => $teamId,
            'user_id'           => $userId,
            'source_type'       => $sourceType,
            'source_id'         => $sourceId,
            'channel'           => ($payload['channel'] ?? Channel::System->value),
            'sender_kind'       => (string) ($payload['sender_kind'] ?? 'system'),
            'sender_label'      => (string) ($payload['sender_label'] ?? 'System'),
            'sender_identifier' => (string) ($payload['sender_identifier'] ?? ($payload['sender_label'] ?? 'system')),
            'subject'           => Str::limit($subject, 250, ''),
            'preview'           => Str::limit(trim(strip_tags((string) ($body ?? $subject))), 200),
            'body'              => $body,
            'body_format'       => 'text',
            'direction'         => 'inbound',
            'status'            => InboxItemStatus::New->value,
            'received_at'       => $receivedAt,
            'importance_score'  => isset($payload['importance']) ? (float) $payload['importance'] : null,
        ]);
    }

    /**
     * Quelle bestimmen: Model → morph; sonst explizit; sonst der User selbst.
     *
     * @return array{0:string,1:int}
     */
    protected function resolveSource(array $payload, int $userId): array
    {
        $source = $payload['source'] ?? null;

        if ($source instanceof Model) {
            return [$source->getMorphClass(), (int) $source->getKey()];
        }

        if (!empty($payload['source_type']) && !empty($payload['source_id'])) {
            return [(string) $payload['source_type'], (int) $payload['source_id']];
        }

        // Fallback: System-Nachricht ohne eigenes Objekt → an den User selbst gehängt.
        return [(new User())->getMorphClass(), $userId];
    }
}
