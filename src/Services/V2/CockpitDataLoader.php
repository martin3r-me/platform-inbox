<?php

namespace Platform\Inbox\Services\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemEnrichment;

/**
 * Loads the full context the V2 thread cockpit needs in one round-trip:
 * the InboxItem, its primary enrichment, participants, linked entities,
 * and the channel-appropriate thread history.
 *
 * Channel-aware. Mail → conversation siblings. Message → chat messages.
 * Everything else returns no thread history (the item *is* the thread).
 *
 * Kept as a service rather than methods on the model so Show.php (V1) and
 * the ShowItemTool can later converge on the same loader.
 */
class CockpitDataLoader
{
    /**
     * @return array{
     *     item: InboxItem,
     *     enrichment: array|null,
     *     participants: array,
     *     linked_entities: array,
     *     thread_history: array{kind: string, messages: array}|null,
     * }
     */
    public function load(InboxItem $item): array
    {
        $primary = InboxItemEnrichment::query()
            ->where('inbox_item_id', $item->id)
            ->where('is_primary', true)
            ->where('status', InboxItemEnrichment::STATUS_DONE)
            ->latest()
            ->first();

        $enrichment = $primary ? [
            'id' => $primary->id,
            'template_key' => $primary->template_key,
            'provider' => $primary->provider,
            'output' => $primary->output,
            'tldr' => $primary->output['tldr'] ?? null,
            'headline' => $primary->output['headline'] ?? null,
            'action_items' => $primary->output['action_items'] ?? [],
            'summary' => $primary->output['summary'] ?? null,
        ] : null;

        $participants = $item->participants()
            ->orderBy('role')
            ->limit(50)
            ->get()
            ->map(fn ($p) => [
                'role' => $p->role,
                'identifier' => $p->identifier,
                'display_name' => $p->display_name,
                'entity_id' => $p->entity_id,
            ])
            ->all();

        $linkedEntities = $this->loadLinkedEntities($item);
        $threadHistory = $this->loadThreadHistory($item);

        return [
            'item' => $item,
            'enrichment' => $enrichment,
            'participants' => $participants,
            'linked_entities' => $linkedEntities,
            'thread_history' => $threadHistory,
        ];
    }

    /**
     * Linked entities via organization_entity_links morph table — guard the
     * table existence so an organization-less deploy still renders.
     *
     * @return array<int, array{id: int, name: string|null, kind: string|null}>
     */
    protected function loadLinkedEntities(InboxItem $item): array
    {
        if (!Schema::hasTable('organization_entity_links')) {
            return [];
        }
        $rows = DB::table('organization_entity_links as l')
            ->leftJoin('organization_entities as e', 'e.id', '=', 'l.entity_id')
            ->where('l.linkable_type', 'inbox_item')
            ->where('l.linkable_id', $item->id)
            ->select('e.id', 'e.name', 'e.uuid')
            ->limit(20)
            ->get();
        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'name' => $r->name,
            'uuid' => $r->uuid,
        ])->all();
    }

    /**
     * @return array{kind: string, messages: array}|null
     */
    protected function loadThreadHistory(InboxItem $item): ?array
    {
        // Mail: sibling sessions sharing the conversation_id.
        if ($item->source_type === 'user_connector_mail_session'
            && Schema::hasTable('user_connector_mail_sessions')
        ) {
            $session = DB::table('user_connector_mail_sessions')
                ->where('id', $item->source_id)
                ->first(['conversation_id', 'connection_id']);
            if (!$session || !$session->conversation_id) {
                return null;
            }
            $rows = DB::table('user_connector_mail_sessions')
                ->where('connection_id', $session->connection_id)
                ->where('conversation_id', $session->conversation_id)
                ->orderBy('received_at')
                ->get([
                    'id', 'external_mail_id', 'direction', 'from_address',
                    'from_name', 'subject', 'body_preview', 'received_at', 'is_read',
                ]);
            return [
                'kind' => 'mail_thread',
                'messages' => $rows->map(fn ($r) => [
                    'session_id' => (int) $r->id,
                    'external_mail_id' => $r->external_mail_id,
                    'direction' => $r->direction,
                    'from_name' => $r->from_name,
                    'from_address' => $r->from_address,
                    'subject' => $r->subject,
                    'preview' => $r->body_preview,
                    'received_at' => $r->received_at,
                    'is_read' => (bool) $r->is_read,
                ])->all(),
            ];
        }

        // Teams chat: messages of the session, chronological.
        if ($item->source_type === 'user_connector_message_session'
            && Schema::hasTable('user_connector_chat_messages')
        ) {
            $rows = DB::table('user_connector_chat_messages')
                ->where('message_session_id', $item->source_id)
                ->orderBy('sent_at')
                ->limit(500)
                ->get([
                    'external_message_id', 'from_identifier', 'from_user_id',
                    'body_preview', 'body', 'direction', 'sent_at',
                ]);
            return [
                'kind' => 'chat_thread',
                'messages' => $rows->map(fn ($r) => [
                    'external_message_id' => $r->external_message_id,
                    'from' => $r->from_identifier,
                    'direction' => $r->direction,
                    'preview' => $r->body_preview,
                    'body' => $r->body,
                    'sent_at' => $r->sent_at,
                ])->all(),
            ];
        }

        return null;
    }
}
