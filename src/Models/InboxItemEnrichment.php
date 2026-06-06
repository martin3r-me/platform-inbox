<?php

namespace Platform\Inbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

class InboxItemEnrichment extends Model
{
    protected $table = 'inbox_item_enrichments';

    protected $fillable = [
        'uuid',
        'inbox_item_id',
        'template_id',
        'template_key',
        'template_version',
        'status',
        'provider',
        'provider_model',
        'output',
        'tokens_input',
        'tokens_output',
        'cost_micro_cents',
        'duration_ms',
        'confidence',
        'is_primary',
        'error_message',
        'run_at',
    ];

    protected $casts = [
        'output' => 'array',
        'tokens_input' => 'integer',
        'tokens_output' => 'integer',
        'cost_micro_cents' => 'integer',
        'duration_ms' => 'integer',
        'template_version' => 'integer',
        'confidence' => 'decimal:2',
        'is_primary' => 'boolean',
        'run_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = UuidV7::generate();
            }
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class, 'inbox_item_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(InboxEnrichmentTemplate::class, 'template_id');
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeDone($query)
    {
        return $query->where('status', self::STATUS_DONE);
    }
}
