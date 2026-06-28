<?php

namespace Platform\Inbox\Enums;

/**
 * Relations between two InboxItems, recorded in `inbox_item_links`.
 *
 * The relation is directional — `from` plays a specific role with
 * respect to `to`. The string values are stable contract identifiers
 * that may appear in serialised payloads and external integrations,
 * so don't rename them without a migration.
 */
enum InboxItemRelation: string
{
    /**
     * The `from` item provides supplementary content for the `to` item.
     *
     * Example: a Whisper recording (from) supplements a meeting (to).
     * The recording adds audio + transcript on top of the meeting's
     * calendar metadata. Both items remain first-class; the link just
     * lets enrichment + UI surface the relationship.
     */
    case Supplements = 'supplements';

    /**
     * The `from` item is the transcript of the `to` item.
     *
     * More specific than Supplements — pick this when the contributing
     * payload is specifically a textual rendering of the audio/video
     * the `to` item describes.
     */
    case TranscriptOf = 'transcript_of';

    /**
     * The `from` item is a direct reply to the `to` item.
     *
     * Used for mail threading and similar conversation chains.
     */
    case ReplyTo = 'reply_to';

    /**
     * Loose reference — `from` mentions or links to `to` without one
     * of the more specific semantics above.
     */
    case References = 'references';

    public function label(): string
    {
        return match ($this) {
            self::Supplements => 'Ergänzt',
            self::TranscriptOf => 'Transkript von',
            self::ReplyTo => 'Antwort auf',
            self::References => 'Bezieht sich auf',
        };
    }
}
