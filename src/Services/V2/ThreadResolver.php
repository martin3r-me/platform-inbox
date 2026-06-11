<?php

namespace Platform\Inbox\Services\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Inbox\Models\InboxItem;

/**
 * Resolves an InboxItem's thread_key from the underlying source model.
 * The key is the join coordinate the V2 stream uses to fold messages into
 * sender × thread cards.
 *
 *   mail     → user_connector_mail_sessions.conversation_id
 *   message  → user_connector_message_sessions.chat_id
 *   call / meeting / voice → null (the item is its own thread)
 *
 * Lives as a tiny service rather than on the model because cross-module
 * source tables aren't guaranteed to exist (user-connectors can be absent
 * in slimmed-down deploys).
 */
class ThreadResolver
{
    public function for(InboxItem $item): ?string
    {
        return match ($item->source_type) {
            'user_connector_mail_session' => $this->lookup(
                'user_connector_mail_sessions',
                (int) $item->source_id,
                'conversation_id',
            ),
            'user_connector_message_session' => $this->lookup(
                'user_connector_message_sessions',
                (int) $item->source_id,
                'chat_id',
            ),
            default => null,
        };
    }

    /**
     * Single-column lookup with table-existence guard. Returns null for
     * missing rows or absent source modules — the V2 stream tolerates
     * thread_key = null and treats those as standalone items.
     */
    protected function lookup(string $table, int $id, string $column): ?string
    {
        if (!Schema::hasTable($table)) {
            return null;
        }
        $value = DB::table($table)->where('id', $id)->value($column);
        return $value !== null ? (string) $value : null;
    }
}
