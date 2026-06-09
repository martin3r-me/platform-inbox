<?php

namespace Platform\Inbox\Tools\Items;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemEnrichment;
use Platform\Inbox\Models\InboxItemHandoff;
use Platform\Inbox\Services\InboxEntityLinkService;

/**
 * Full item context for triage decisions: body, primary enrichment
 * (headline/tldr/summary/action_items/...), participants, linked entities,
 * existing handoffs. Single round-trip — everything the LLM needs.
 */
class ShowItemTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.items.show.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert ein einzelnes Inbox-Item mit vollem Kontext: Body, primäre Anreicherung, '
            . 'Teilnehmer, verlinkte Entities, vorhandene Handoffs. Für die Triage-Entscheidung.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $item = InboxItem::query()
            ->where('id', (int) ($arguments['id'] ?? 0))
            ->where('user_id', $context->user->id)
            ->first();
        if (!$item) {
            return ToolResult::error('NOT_FOUND', 'Item nicht gefunden oder kein Zugriff.');
        }

        $primary = InboxItemEnrichment::query()
            ->where('inbox_item_id', $item->id)
            ->where('is_primary', true)
            ->where('status', InboxItemEnrichment::STATUS_DONE)
            ->latest()
            ->first();

        $handoffs = InboxItemHandoff::query()
            ->where('inbox_item_id', $item->id)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'kind' => $h->kind,
                'target_type' => $h->target_type,
                'target_id' => $h->target_id,
                'enrichment_id' => $h->enrichment_id,
                'action_item_index' => $h->action_item_index,
            ])
            ->all();

        $participants = $item->participants()->orderBy('role')->limit(50)->get()
            ->map(fn ($p) => [
                'role' => $p->role,
                'identifier' => $p->identifier,
                'identifier_kind' => $p->identifier_kind,
                'display_name' => $p->display_name,
                'entity_id' => $p->entity_id,
                'entity_confidence' => $p->entity_confidence,
            ])->all();

        $entities = app(InboxEntityLinkService::class)->linksFor($item);

        // Thread-Verlauf — gibt der Triage Kontext jenseits der letzten
        // Nachricht. Für Mail: alle Sessions mit derselben conversation_id;
        // für Teams: alle chat_messages der Session.
        $thread = $this->fetchThreadHistory($item);

        return ToolResult::success([
            'id' => $item->id,
            'channel' => $item->channel?->value,
            'direction' => $item->direction,
            'status' => $item->status?->value,
            'subject' => $item->subject,
            'sender_label' => $item->sender_label,
            'sender_identifier' => $item->sender_identifier,
            'sender_kind' => $item->sender_kind,
            'received_at' => $item->received_at?->toIso8601String(),
            'snoozed_until' => $item->snoozed_until?->toIso8601String(),
            'language' => $item->language,
            'body' => $item->body,
            'preview' => $item->preview,
            'enrichment' => $primary ? [
                'id' => $primary->id,
                'template_key' => $primary->template_key,
                'template_version' => $primary->template_version,
                'provider' => $primary->provider,
                'provider_model' => $primary->provider_model,
                'output' => $primary->output,
                'cost_micro_cents' => $primary->cost_micro_cents,
                'tokens_input' => $primary->tokens_input,
                'tokens_output' => $primary->tokens_output,
            ] : null,
            'participants' => $participants,
            'linked_entities' => $entities,
            'handoffs' => $handoffs,
            'thread' => $thread,
        ]);
    }

    /**
     * Loads the entire conversation context for the item, channel-aware:
     *   - mail:    all mail_session rows sharing the conversation_id
     *   - message: all chat_messages of the message_session
     * Returns null when no context is available (calls, recordings, …).
     *
     * @return array{kind: string, messages: array}|null
     */
    protected function fetchThreadHistory(InboxItem $item): ?array
    {
        if ($item->source_type === 'user_connector_mail_session' && \Illuminate\Support\Facades\Schema::hasTable('user_connector_mail_sessions')) {
            $session = \Illuminate\Support\Facades\DB::table('user_connector_mail_sessions')
                ->where('id', $item->source_id)
                ->first(['conversation_id', 'connection_id']);
            if (!$session || !$session->conversation_id) {
                return null;
            }
            $rows = \Illuminate\Support\Facades\DB::table('user_connector_mail_sessions')
                ->where('connection_id', $session->connection_id)
                ->where('conversation_id', $session->conversation_id)
                ->orderBy('received_at')
                ->get(['id', 'external_mail_id', 'direction', 'from_address', 'from_name', 'subject', 'body_preview', 'received_at', 'is_read']);
            return [
                'kind' => 'mail_thread',
                'messages' => $rows->map(fn ($r) => [
                    'session_id' => (int) $r->id,
                    'external_mail_id' => $r->external_mail_id,
                    'direction' => $r->direction,
                    'from' => trim((string) ($r->from_name ?? '') . ' <' . (string) ($r->from_address ?? '') . '>'),
                    'subject' => $r->subject,
                    'preview' => $r->body_preview,
                    'received_at' => $r->received_at,
                    'is_read' => (bool) $r->is_read,
                ])->all(),
            ];
        }

        if ($item->source_type === 'user_connector_message_session' && \Illuminate\Support\Facades\Schema::hasTable('user_connector_chat_messages')) {
            $rows = \Illuminate\Support\Facades\DB::table('user_connector_chat_messages')
                ->where('message_session_id', $item->source_id)
                ->orderBy('sent_at')
                ->limit(500)
                ->get(['external_message_id', 'from_identifier', 'from_user_id', 'body_preview', 'body', 'direction', 'sent_at']);
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

    public function getMetadata(): array
    {
        return [
            'category' => 'inspection',
            'tags' => ['inbox', 'items', 'show', 'triage'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'read',
            'idempotent' => true,
        ];
    }
}
