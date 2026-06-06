<?php

namespace Platform\Inbox\Tools\Templates;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Models\InboxEnrichmentTemplate;

/**
 * List enrichment templates visible to the current team:
 *   - the team's own templates (team_id = current team)
 *   - global shipped templates (team_id = null), unless include_global=false
 *
 * Filters: channel (mail/call/...), only_active.
 */
class ListTemplatesTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.enrichment_templates.list.GET';
    }

    public function getDescription(): string
    {
        return 'Listet alle Enrichment-Templates, die für das aktuelle Team verfügbar sind '
            . '(team-eigene + globale). Optional gefiltert nach Kanal oder Aktivierungsstatus.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'channel' => ['type' => 'string', 'description' => 'Filter: mail | call | message | meeting | recording'],
                'only_active' => ['type' => 'boolean', 'description' => 'Nur aktive Templates. Default: true.'],
                'include_global' => ['type' => 'boolean', 'description' => 'Auch globale Templates (team_id NULL). Default: true.'],
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

        $onlyActive = $arguments['only_active'] ?? true;
        $includeGlobal = $arguments['include_global'] ?? true;
        $channel = $arguments['channel'] ?? null;

        $query = InboxEnrichmentTemplate::query()
            ->where(function ($q) use ($teamId, $includeGlobal) {
                $q->where('team_id', $teamId);
                if ($includeGlobal) {
                    $q->orWhereNull('team_id');
                }
            })
            ->orderBy('name');

        if ($onlyActive) {
            $query->active();
        }
        if ($channel) {
            $query->forChannel($channel);
        }

        $templates = $query->limit(200)->get()->map(fn ($t) => [
            'id' => $t->id,
            'uuid' => $t->uuid,
            'key' => $t->key,
            'name' => $t->name,
            'description' => $t->description,
            'applicable_channels' => $t->applicable_channels,
            'preferred_provider' => $t->preferred_provider,
            'version' => $t->version,
            'is_active' => $t->is_active,
            'is_default_for_channel' => $t->is_default_for_channel,
            'scope' => $t->team_id === null ? 'global' : 'team',
        ])->all();

        return ToolResult::success([
            'count' => count($templates),
            'templates' => $templates,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'inspection',
            'tags' => ['inbox', 'templates', 'enrichment', 'list'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'read',
            'idempotent' => true,
        ];
    }
}
