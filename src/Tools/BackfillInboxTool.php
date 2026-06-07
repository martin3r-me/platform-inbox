<?php

namespace Platform\Inbox\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Inbox\Services\InboxIngestionService;

/**
 * Re-runs the user-connector → inbox ingest over a wide historical window
 * so previously-missed sessions land in the inbox. Safe to re-run: the
 * underlying whereNotExists guard makes ingestion idempotent.
 *
 * Loops in 2000-row chunks until either a round returns zero or the safety
 * cap is hit — that's how we cover deep history without exploding memory.
 */
class BackfillInboxTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.backfill.POST';
    }

    public function getDescription(): string
    {
        return 'Backfillt die Inbox: läuft den user-connector → inbox-Ingest mit einem grossen '
            . 'Zeitfenster (default 365 Tage) und loopt in 2000er-Chunks, bis nichts Neues mehr kommt. '
            . 'Idempotent — bereits importierte Sessions werden übersprungen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => [
                    'type' => 'integer',
                    'description' => 'Zeitfenster in Tagen. Default: 365.',
                    'minimum' => 1,
                    'maximum' => 3650,
                ],
                'max_rounds' => [
                    'type' => 'integer',
                    'description' => 'Sicherheits-Cap auf die Loop-Runden. Default: 20 (= bis zu 40k Items).',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $days = (int) ($arguments['days'] ?? 365);
        $maxRounds = (int) ($arguments['max_rounds'] ?? 20);
        $minutes = $days * 24 * 60;

        $service = app(InboxIngestionService::class);

        $total = 0;
        $rounds = 0;
        $perRound = [];
        for ($r = 1; $r <= $maxRounds; $r++) {
            $created = $service->ingestRecent($minutes);
            $perRound[] = ['round' => $r, 'created' => $created];
            $total += $created;
            $rounds = $r;
            if ($created === 0) {
                break;
            }
        }

        return ToolResult::success([
            'total_created' => $total,
            'rounds_used' => $rounds,
            'rounds_capped' => $rounds === $maxRounds && end($perRound)['created'] > 0,
            'window_days' => $days,
            'per_round' => $perRound,
            'message' => $total === 0
                ? 'Nichts Neues — alle Sessions im Fenster sind bereits ingested oder es gibt keine.'
                : "Backfill abgeschlossen: {$total} neue Inbox-Items in {$rounds} Runde(n).",
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'backfill', 'ingest', 'maintenance'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
