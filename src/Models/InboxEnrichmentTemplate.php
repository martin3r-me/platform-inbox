<?php

namespace Platform\Inbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\Core\Models\Team;
use Symfony\Component\Uid\UuidV7;

class InboxEnrichmentTemplate extends Model
{
    use SoftDeletes;

    protected $table = 'inbox_enrichment_templates';

    protected $fillable = [
        'uuid',
        'team_id',
        'key',
        'name',
        'description',
        'applicable_channels',
        'output_schema',
        'prompt_template',
        'system_prompt',
        'preferred_provider',
        'version',
        'is_active',
        'is_default_for_channel',
    ];

    protected $casts = [
        'applicable_channels' => 'array',
        'output_schema' => 'array',
        'version' => 'integer',
        'is_active' => 'boolean',
        'is_default_for_channel' => 'boolean',
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForChannel($query, string $channel)
    {
        return $query->whereJsonContains('applicable_channels', $channel);
    }

    /**
     * Default template lookup for a (channel, team) combination.
     * Falls back to global default (team_id NULL) when no team-specific override exists.
     */
    public static function defaultForChannel(string $channel, ?int $teamId): ?self
    {
        $query = static::query()
            ->active()
            ->forChannel($channel)
            ->where('is_default_for_channel', true);

        if ($teamId !== null) {
            $teamSpecific = (clone $query)->where('team_id', $teamId)->first();
            if ($teamSpecific) {
                return $teamSpecific;
            }
        }

        return $query->whereNull('team_id')->first();
    }
}
