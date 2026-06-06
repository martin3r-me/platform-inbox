<?php

namespace Platform\Inbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Symfony\Component\Uid\UuidV7;

class InboxLinkRule extends Model
{
    use SoftDeletes;

    protected $table = 'inbox_link_rules';

    protected $fillable = [
        'uuid',
        'team_id',
        'user_id',
        'name',
        'priority',
        'is_active',
        'channel',
        'sender_kind',
        'sender_identifier',
        'sender_pattern',
        'subject_pattern',
        'body_pattern',
        'entity_ids',
        'also_mark_as',
        'matched_count',
        'last_matched_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'matched_count' => 'integer',
        'entity_ids' => 'array',
        'last_matched_at' => 'datetime',
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

    public function autoLinkEvents(): HasMany
    {
        return $this->hasMany(InboxAutoLinkEvent::class, 'rule_id');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
