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
        ]);
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
