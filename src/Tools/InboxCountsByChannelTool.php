<?php

namespace Platform\Inbox\Tools;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Quick diagnostic: counts inbox items for the current user broken down by
 * channel × status. Useful when the inbox UI looks wrong and we need to
 * tell ingestion problems apart from filter/visibility problems.
 */
class InboxCountsByChannelTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.debug.counts.GET';
    }

    public function getDescription(): string
    {
        return 'Diagnose: Zählt Inbox-Items des aktuellen Users gruppiert nach Channel × Status. '
            . 'Hilft beim Unterscheiden von Ingest- vs. Filter-Problemen.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if (!$context->user) {
            return ToolResult::error('AUTH_ERROR', 'Benutzer nicht authentifiziert.');
        }

        $rows = DB::table('inbox_items')
            ->where('user_id', $context->user->id)
            ->selectRaw('channel, status, COUNT(*) as cnt')
            ->groupBy('channel', 'status')
            ->orderBy('channel')
            ->orderBy('status')
            ->get();

        $byChannel = [];
        $totals = ['new' => 0, 'done' => 0, 'ignored' => 0, 'snoozed' => 0, 'all' => 0];
        foreach ($rows as $r) {
            $byChannel[$r->channel][$r->status] = (int) $r->cnt;
            $totals[$r->status] = ($totals[$r->status] ?? 0) + (int) $r->cnt;
            $totals['all'] += (int) $r->cnt;
        }

        return ToolResult::success([
            'by_channel' => $byChannel,
            'totals' => $totals,
            'user_id' => $context->user->id,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'inspection',
            'tags' => ['inbox', 'debug', 'counts', 'diagnostics'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'read',
            'idempotent' => true,
        ];
    }
}
