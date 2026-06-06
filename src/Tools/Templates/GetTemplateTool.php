<?php

namespace Platform\Inbox\Tools\Templates;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Models\InboxEnrichmentTemplate;

/**
 * Returns a single enrichment template with full prompt + system_prompt +
 * output_schema. Looks up by id or uuid. Team-scoped: only the current
 * team's templates or globals are visible.
 */
class GetTemplateTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.enrichment_templates.show.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert ein einzelnes Template inklusive Prompt, System-Prompt und Output-Schema. '
            . 'Identifikator: id oder uuid.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'DB-ID des Templates.'],
                'uuid' => ['type' => 'string', 'description' => 'UUID des Templates.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }
        $teamId = $context->team?->id ?? $context->user->currentTeam?->id;
        if (!$teamId) {
            return ToolResult::error('AUTH_ERROR', 'Kein Team im Kontext.');
        }

        $id = $arguments['id'] ?? null;
        $uuid = $arguments['uuid'] ?? null;
        if (!$id && !$uuid) {
            return ToolResult::error('VALIDATION_ERROR', 'id oder uuid muss gesetzt sein.');
        }

        $query = InboxEnrichmentTemplate::query()
            ->where(function ($q) use ($teamId) {
                $q->where('team_id', $teamId)->orWhereNull('team_id');
            });
        if ($id) {
            $query->where('id', $id);
        } else {
            $query->where('uuid', $uuid);
        }
        $tpl = $query->first();

        if (!$tpl) {
            return ToolResult::error('NOT_FOUND', 'Template nicht gefunden oder kein Zugriff.');
        }

        return ToolResult::success([
            'id' => $tpl->id,
            'uuid' => $tpl->uuid,
            'key' => $tpl->key,
            'name' => $tpl->name,
            'description' => $tpl->description,
            'applicable_channels' => $tpl->applicable_channels,
            'output_schema' => $tpl->output_schema,
            'prompt_template' => $tpl->prompt_template,
            'system_prompt' => $tpl->system_prompt,
            'preferred_provider' => $tpl->preferred_provider,
            'version' => $tpl->version,
            'is_active' => $tpl->is_active,
            'is_default_for_channel' => $tpl->is_default_for_channel,
            'scope' => $tpl->team_id === null ? 'global' : 'team',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'inspection',
            'tags' => ['inbox', 'templates', 'enrichment', 'show'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'read',
            'idempotent' => true,
        ];
    }
}
