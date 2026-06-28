<?php

namespace Platform\Inbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Inbox\Enums\InboxItemRelation;
use Symfony\Component\Uid\UuidV7;

/**
 * Typed relation between two InboxItems. See InboxItemRelation for the
 * supported relation kinds. Created via InboxItemLinkService — direct
 * model writes from outside the Inbox module are discouraged.
 */
class InboxItemLink extends Model
{
    protected $table = 'inbox_item_links';

    protected $fillable = [
        'uuid',
        'from_inbox_item_id',
        'to_inbox_item_id',
        'relation',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function fromItem(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class, 'from_inbox_item_id');
    }

    public function toItem(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class, 'to_inbox_item_id');
    }

    public function relationEnum(): ?InboxItemRelation
    {
        return InboxItemRelation::tryFrom((string) $this->relation);
    }
}
