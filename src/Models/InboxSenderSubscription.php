<?php

namespace Platform\Inbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\Inbox\Enums\SubscriptionStatus;
use Symfony\Component\Uid\UuidV7;

class InboxSenderSubscription extends Model
{
    protected $table = 'inbox_sender_subscriptions';

    protected $fillable = [
        'uuid',
        'team_id',
        'user_id',
        'sender_kind',
        'sender_identifier',
        'status',
        'is_vip',
        'label',
        'notes',
        'last_seen_at',
    ];

    protected $casts = [
        'status' => SubscriptionStatus::class,
        'is_vip' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    /** Normalize an identifier consistently across the module. */
    public static function normalize(?string $value, string $kind): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return match ($kind) {
            'email' => strtolower(trim($value)),
            'phone' => preg_replace('/\D+/', '', $value) ?: null,
            'teams' => strtolower(trim($value)),
            default => trim($value),
        };
    }

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

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeUnsubscribed($query)
    {
        return $query->where('status', SubscriptionStatus::Unsubscribed->value);
    }
}
