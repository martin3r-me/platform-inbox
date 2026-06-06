<?php

namespace Platform\Inbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Core\Models\Team;
use Symfony\Component\Uid\UuidV7;

class InboxVoiceProfile extends Model
{
    protected $table = 'inbox_voice_profiles';

    protected $fillable = [
        'uuid',
        'team_id',
        'entity_id',
        'display_name',
        'embedding',
        'confirmed_count',
        'last_seen_at',
    ];

    protected $casts = [
        'embedding' => 'array',
        'confirmed_count' => 'integer',
        'last_seen_at' => 'datetime',
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

    /**
     * Lookup a profile for a (team, entity) pair — most stable identity
     * source. Falls back to display_name match if no entity is given.
     */
    public static function findForTeam(int $teamId, ?int $entityId, ?string $displayName): ?self
    {
        if ($entityId !== null) {
            return static::query()
                ->where('team_id', $teamId)
                ->where('entity_id', $entityId)
                ->first();
        }
        if ($displayName !== null && $displayName !== '') {
            return static::query()
                ->where('team_id', $teamId)
                ->where('display_name', $displayName)
                ->first();
        }
        return null;
    }
}
