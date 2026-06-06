<?php

namespace Platform\Inbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxItemSegment extends Model
{
    protected $table = 'inbox_item_segments';

    protected $fillable = [
        'inbox_item_id',
        'start_seconds',
        'end_seconds',
        'speaker_label',
        'text',
        'confidence',
    ];

    protected $casts = [
        'start_seconds' => 'integer',
        'end_seconds' => 'integer',
        'confidence' => 'decimal:2',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class, 'inbox_item_id');
    }
}
