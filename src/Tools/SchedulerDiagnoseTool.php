<?php

namespace Platform\Inbox\Tools;

use Illuminate\Console\Scheduling\Schedule;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Diagnose des inbox:ingest-Cron-Status. Zeigt für jeden registrierten
 * inbox:ingest-Event:
 *   - die Cron-Expression (sollte alle 5 Minuten triggern)
 *   - den errechneten mutexName (Cache-Lock-Key)
 *   - ob der withoutOverlapping-Lock gerade gehalten wird
 *   - ob die Cron-Expression jetzt passen würde
 *
 * Hauptverdächtiger bei "Cron läuft nicht": ein gehaltener Lock ohne
 * laufenden Prozess (Zombie aus früherem Crash). Das Tool liefert die
 * Befunde, der Unlock-Tool releast bei Bedarf.
 */
class SchedulerDiagnoseTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.scheduler.diagnose.GET';
    }

    public function getDescription(): string
    {
        return 'Zeigt den Zustand des inbox:ingest-Cron-Schedules: registrierte Events, '
            . 'Cache-Lock-Status (withoutOverlapping), ob die Expression aktuell triggern würde. '
            . 'Hauptverdächtiger bei stillem Cron-Ausfall ist ein hängender Lock aus einem früheren Crash.';
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

        $schedule = app(Schedule::class);
        $events = [];

        foreach ($schedule->events() as $event) {
            $command = $event->command ?? '';
            if (!str_contains($command, 'inbox:ingest')) {
                continue;
            }

            $mutexName = method_exists($event, 'mutexName') ? $event->mutexName() : null;
            $isLocked = null;
            if ($event->mutex ?? null) {
                try {
                    $isLocked = $event->mutex->exists($event);
                } catch (\Throwable) {
                    // EventMutex might not implement exists() in older Laravel versions.
                }
            }

            $events[] = [
                'command' => $command,
                'expression' => $event->expression ?? null,
                'mutex_name' => $mutexName,
                'is_locked' => $isLocked,
                'without_overlapping' => (bool) ($event->withoutOverlapping ?? false),
                'on_one_server' => (bool) ($event->onOneServer ?? false),
                'expression_passes_now' => $event->isDue($this->app('events')) ?? null,
            ];
        }

        return ToolResult::success([
            'events_count' => count($events),
            'events' => $events,
            'hint' => count($events) === 0
                ? 'Kein inbox:ingest-Event im Schedule registriert — Service-Provider-Bug.'
                : 'Wenn is_locked=true und keine echte Cron-Iteration läuft, hängt der Lock — inbox.scheduler.unlock.POST released ihn.',
        ]);
    }

    /**
     * Wrapper around app() that tolerates being called outside Laravel
     * (e.g. in tests). Keeps the tool itself stateless.
     */
    protected function app(string $service): mixed
    {
        try {
            return app($service);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'inspection',
            'tags' => ['inbox', 'scheduler', 'cron', 'diagnose', 'maintenance'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'read',
            'idempotent' => true,
        ];
    }
}
