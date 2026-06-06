<?php

namespace Platform\Inbox\Tools\Templates;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Models\InboxEnrichmentTemplate;

/**
 * Create or update a team-scoped enrichment template.
 *   - Identifies by (team_id, key) — same key in the same team = same template.
 *   - On update, version is auto-bumped if prompt_template, system_prompt,
 *     or output_schema changed. Other field changes don't trigger a bump.
 *   - Global templates (team_id NULL) cannot be created or modified through
 *     this tool — they're shipped via migrations only.
 */
class UpsertTemplateTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.enrichment_templates.upsert.POST';
    }

    public function getDescription(): string
    {
        return 'Legt ein Enrichment-Template an oder aktualisiert es (Identifikator: key + aktuelles Team). '
            . 'Bei Änderungen an prompt_template, system_prompt oder output_schema wird die Version automatisch hochgezählt — '
            . 'damit existierende Anreicherungsläufe forensisch zuordenbar bleiben.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'Stabiler Identifier (z. B. "standard-mail"). Pflicht.'],
                'name' => ['type' => 'string', 'description' => 'Anzeigename.'],
                'description' => ['type' => 'string'],
                'applicable_channels' => [
                    'type' => 'array',
                    'description' => 'Liste von Kanälen — mindestens einer.',
                    'items' => ['type' => 'string', 'enum' => ['mail', 'call', 'message', 'meeting', 'recording']],
                ],
                'prompt_template' => [
                    'type' => 'string',
                    'description' => 'Prompt-Template. Placeholder: {body} {subject} {sender} {channel} {language} {participants_list}.',
                ],
                'system_prompt' => ['type' => 'string'],
                'preferred_provider' => [
                    'type' => 'string',
                    'description' => 'Provider:Model, z. B. "openai:gpt-4o-mini" oder "claude:claude-haiku-4-5".',
                ],
                'output_schema' => [
                    'type' => 'object',
                    'description' => 'JSON-Schema des erwarteten Outputs.',
                ],
                'is_active' => ['type' => 'boolean', 'description' => 'Default: true.'],
                'is_default_for_channel' => ['type' => 'boolean', 'description' => 'Default: false.'],
            ],
            'required' => ['key'],
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

        $key = trim((string) ($arguments['key'] ?? ''));
        if ($key === '') {
            return ToolResult::error('VALIDATION_ERROR', 'key ist erforderlich.');
        }

        $existing = InboxEnrichmentTemplate::query()
            ->where('team_id', $teamId)
            ->where('key', $key)
            ->first();

        $isCreate = !$existing;

        if ($isCreate) {
            // Need a minimum payload to create.
            $required = ['name', 'applicable_channels', 'prompt_template', 'output_schema'];
            foreach ($required as $r) {
                if (!isset($arguments[$r]) || (is_array($arguments[$r]) && empty($arguments[$r])) || (is_string($arguments[$r]) && trim($arguments[$r]) === '')) {
                    return ToolResult::error('VALIDATION_ERROR', "$r ist beim Anlegen erforderlich.");
                }
            }
            if (!is_array($arguments['output_schema'])) {
                return ToolResult::error('VALIDATION_ERROR', 'output_schema muss ein JSON-Objekt sein.');
            }

            $tpl = InboxEnrichmentTemplate::create([
                'team_id' => $teamId,
                'key' => $key,
                'name' => (string) $arguments['name'],
                'description' => isset($arguments['description']) ? (string) $arguments['description'] : null,
                'applicable_channels' => array_values((array) $arguments['applicable_channels']),
                'prompt_template' => (string) $arguments['prompt_template'],
                'system_prompt' => isset($arguments['system_prompt']) ? (string) $arguments['system_prompt'] : null,
                'preferred_provider' => $arguments['preferred_provider'] ?? 'openai:gpt-4o-mini',
                'output_schema' => $arguments['output_schema'],
                'version' => 1,
                'is_active' => $arguments['is_active'] ?? true,
                'is_default_for_channel' => $arguments['is_default_for_channel'] ?? false,
            ]);

            return ToolResult::success([
                'action' => 'created',
                'id' => $tpl->id,
                'uuid' => $tpl->uuid,
                'key' => $tpl->key,
                'version' => $tpl->version,
            ]);
        }

        // Update: only patch supplied fields. Bump version if prompt/system/schema changed.
        $promptChanged = isset($arguments['prompt_template']) && (string) $arguments['prompt_template'] !== (string) $existing->prompt_template;
        $systemChanged = array_key_exists('system_prompt', $arguments) && (string) ($arguments['system_prompt'] ?? '') !== (string) ($existing->system_prompt ?? '');
        $schemaChanged = isset($arguments['output_schema']) && $arguments['output_schema'] !== $existing->output_schema;

        $payload = [];
        foreach (['name', 'description', 'applicable_channels', 'prompt_template', 'system_prompt', 'preferred_provider', 'output_schema', 'is_active', 'is_default_for_channel'] as $f) {
            if (array_key_exists($f, $arguments)) {
                $payload[$f] = $arguments[$f];
            }
        }
        if (isset($payload['applicable_channels'])) {
            if (!is_array($payload['applicable_channels'])) {
                return ToolResult::error('VALIDATION_ERROR', 'applicable_channels muss ein Array sein.');
            }
            $payload['applicable_channels'] = array_values($payload['applicable_channels']);
        }
        if (isset($payload['output_schema']) && !is_array($payload['output_schema'])) {
            return ToolResult::error('VALIDATION_ERROR', 'output_schema muss ein JSON-Objekt sein.');
        }

        if ($promptChanged || $systemChanged || $schemaChanged) {
            $payload['version'] = $existing->version + 1;
        }

        $existing->update($payload);

        return ToolResult::success([
            'action' => 'updated',
            'id' => $existing->id,
            'uuid' => $existing->uuid,
            'key' => $existing->key,
            'version' => $existing->fresh()->version,
            'version_bumped' => $promptChanged || $systemChanged || $schemaChanged,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'templates', 'enrichment', 'upsert', 'write'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
