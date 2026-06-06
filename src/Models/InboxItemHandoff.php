<?php

namespace Platform\Inbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InboxItemHandoff extends Model
{
    protected $table = 'inbox_item_handoffs';

    protected $fillable = [
        'inbox_item_id',
        'kind',
        'target_type',
        'target_id',
        'enrichment_id',
        'action_item_index',
        'user_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'action_item_index' => 'integer',
    ];

    public const KIND_PLANNER_TASK = 'planner_task';
    public const KIND_HELPDESK_TICKET = 'helpdesk_ticket';
    public const KIND_CRM_CONTACT_NOTE = 'crm_contact_note';

    public function item(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class, 'inbox_item_id');
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
