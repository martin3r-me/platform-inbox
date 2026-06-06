<?php

namespace Platform\Inbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxAutoLinkEvent extends Model
{
    public $timestamps = false;

    protected $table = 'inbox_auto_link_events';

    protected $fillable = [
        'inbox_item_id',
        'entity_id',
        'rule_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class, 'inbox_item_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(InboxLinkRule::class, 'rule_id');
    }
}
