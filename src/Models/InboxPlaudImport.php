<?php

namespace Platform\Inbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Core\Models\Team;

class InboxPlaudImport extends Model
{
    protected $table = 'inbox_plaud_imports';

    protected $fillable = [
        'team_id',
        'plaud_file_id',
        'inbox_item_id',
        'device_serial',
        'source_url',
        'plaud_recorded_at',
    ];

    protected $casts = [
        'plaud_recorded_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function inboxItem(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class, 'inbox_item_id');
    }
}
