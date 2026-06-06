<?php

namespace Platform\Inbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\Inbox\Enums\Channel;
use Platform\Inbox\Enums\InboxItemStatus;
use Symfony\Component\Uid\UuidV7;

class InboxItem extends Model
{
    protected $table = 'inbox_items';

    protected $fillable = [
        'uuid',
        'team_id',
        'user_id',
        'source_type',
        'source_id',
        'channel',
        'sender_identifier',
        'sender_kind',
        'sender_label',
        'subject',
        'preview',
        'direction',
        'status',
        'snoozed_until',
        'handled_at',
        'received_at',
    ];

    protected $casts = [
        'channel' => Channel::class,
        'status' => InboxItemStatus::class,
        'snoozed_until' => 'datetime',
        'handled_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = UuidV7::generate();
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', InboxItemStatus::New->value);
    }

    public function scopeNotSnoozed($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
        });
    }
}
