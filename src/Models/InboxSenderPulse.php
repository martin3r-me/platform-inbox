<?php

namespace Platform\Inbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Core\Models\User;

/**
 * Per-(user, sender) cache for the V2 inbox: 14-day activity sparkline,
 * sender-level importance score, channel mix. Refreshed by the hourly
 * recompute-scores job and on relevant ingest events.
 *
 * Sender coordinates (kind + identifier) intentionally mirror the rest of the
 * inbox module — same join key everywhere.
 */
class InboxSenderPulse extends Model
{
    protected $table = 'inbox_sender_pulse';

    protected $fillable = [
        'user_id',
        'sender_kind',
        'sender_identifier',
        'pulse_14d',
        'importance_score',
        'channel_mix',
        'last_seen_at',
        'refreshed_at',
    ];

    protected $casts = [
        'pulse_14d' => 'array',
        'channel_mix' => 'array',
        'importance_score' => 'decimal:2',
        'last_seen_at' => 'datetime',
        'refreshed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
