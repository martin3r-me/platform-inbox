<?php

namespace Platform\Inbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxItemParticipant extends Model
{
    protected $table = 'inbox_item_participants';

    protected $fillable = [
        'inbox_item_id',
        'role',
        'identifier',
        'identifier_kind',
        'display_name',
        'entity_id',
        'entity_confidence',
        'voice_profile_id',
    ];

    public const ROLE_SENDER = 'sender';
    public const ROLE_RECIPIENT = 'recipient';
    public const ROLE_CC = 'cc';
    public const ROLE_ORGANIZER = 'organizer';
    public const ROLE_ATTENDEE = 'attendee';
    public const ROLE_SPEAKER = 'speaker';

    public function item(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class, 'inbox_item_id');
    }
}
