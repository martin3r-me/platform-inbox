<?php

namespace Platform\Inbox\Tools;

use Illuminate\Console\Scheduling\Schedule;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Released den withoutOverlapping-Mutex-Lock des inbox:ingest-Cron-Events.
 * Hilft, wenn ein früherer Cron-Run gecrasht ist und den Lock im Cache
 * gehalten hat — alle nachfolgenden 5-Min-Ticks skippen dann still.
 *
 * Nach erfolgreichem Release sollte der nächste Cron-Tick (innerhalb von
 * 5 Minuten) den Command sauber ausführen, sofern das System-Cron
 * (`* * * * * php artisan schedule:run`) überhaupt läuft. Wenn nach
 * Unlock keine neuen Items in der Inbox auftauchen, ist die Ursache eine
 * Ebene tiefer: der Laravel-Scheduler wird gar nicht aufgerufen.
 */
class SchedulerUnlockTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'inbox.scheduler.unlock.POST';
    }

    public function getDescription(): string
    {
        return 'Löscht den withoutOverlapping-Cache-Lock des inbox:ingest-Cron-Events. '
            . 'Notmaßnahme bei still stehendem Cron: ein früherer Crash hat den Lock gesetzt, '
            . 'alle 5-Min-Ticks skippen. Nach Release greift der nächste Tick wieder — sofern '
            . 'das System-Cron `schedule:run` aufruft.';
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
        $cleared = [];
        $skipped = [];

        foreach ($schedule->events() as $event) {
            $command = $event->command ?? '';
            if (!str_contains($command, 'inbox:ingest')) {
                continue;
            }

            $mutexName = method_exists($event, 'mutexName') ? $event->mutexName() : null;
            $wasLocked = null;

            if ($event->mutex ?? null) {
                try {
                    $wasLocked = $event->mutex->exists($event);
                    if ($wasLocked) {
                        $event->mutex->forget($event);
                        $cleared[] = [
                            'command' => $command,
                            'mutex_name' => $mutexName,
                        ];
                    } else {
                        $skipped[] = [
                            'command' => $command,
                            'mutex_name' => $mutexName,
                            'reason' => 'Lock war nicht gesetzt.',
                        ];
                    }
                } catch (\Throwable $e) {
                    $skipped[] = [
                        'command' => $command,
                        'mutex_name' => $mutexName,
                        'reason' => 'Mutex-Operation fehlgeschlagen: ' . $e->getMessage(),
                    ];
                }
            }
        }

        return ToolResult::success([
            'cleared' => $cleared,
            'skipped' => $skipped,
            'next_step' => empty($cleared)
                ? 'Kein Lock gehalten — wenn weiterhin nichts läuft, prüfe das System-Cron (`* * * * * php artisan schedule:run`).'
                : 'Lock released. Nächster 5-Min-Tick sollte ingesten (falls System-Cron läuft).',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['inbox', 'scheduler', 'cron', 'unlock', 'maintenance'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => false,
            'risk_level' => 'write',
            'idempotent' => true,
        ];
    }
}
