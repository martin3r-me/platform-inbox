<?php

namespace Platform\Inbox\Services;

use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemParticipant;
use Platform\Inbox\Models\InboxVoiceProfile;

/**
 * Manages cross-recording speaker identity: when a user confirms
 * "Speaker A is Person-Entity #42" in a recording, this service
 *
 *   1. updates the participant row (entity_id + voice_profile_id)
 *   2. upserts an inbox_voice_profile (team-scoped) with the entity
 *   3. bumps confirmed_count + last_seen_at
 *
 * Future recordings can then auto-suggest the same Person whenever the
 * upstream provider supplies a matching embedding_key OR display_name —
 * the auto-apply lives in the ingest path; this service only handles
 * the explicit confirmation flow.
 */
class InboxVoiceProfileService
{
    public function assign(InboxItem $item, string $speakerLabel, ?int $entityId): ?InboxItemParticipant
    {
        $participant = InboxItemParticipant::query()
            ->where('inbox_item_id', $item->id)
            ->where('role', InboxItemParticipant::ROLE_SPEAKER)
            ->where('identifier', $speakerLabel)
            ->first();

        if (!$participant) {
            return null;
        }

        if ($entityId === null) {
            $participant->update([
                'entity_id' => null,
                'entity_confidence' => null,
                'voice_profile_id' => null,
            ]);
            return $participant->fresh();
        }

        $profile = InboxVoiceProfile::query()
            ->where('team_id', $item->team_id)
            ->where('entity_id', $entityId)
            ->first();

        if ($profile) {
            $profile->update([
                'display_name' => $participant->display_name ?: $profile->display_name,
                'confirmed_count' => $profile->confirmed_count + 1,
                'last_seen_at' => now(),
            ]);
        } else {
            $profile = InboxVoiceProfile::create([
                'team_id' => $item->team_id,
                'entity_id' => $entityId,
                'display_name' => $participant->display_name ?: ('Speaker ' . $speakerLabel),
                'confirmed_count' => 1,
                'last_seen_at' => now(),
            ]);
        }

        $participant->update([
            'entity_id' => $entityId,
            'entity_confidence' => 1.0,
            'voice_profile_id' => $profile->id,
        ]);

        return $participant->fresh();
    }
}
