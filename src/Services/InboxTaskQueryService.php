<?php

namespace Platform\Inbox\Services;

use Platform\Inbox\Contracts\InboxTaskQueryContract;
use Platform\Inbox\Enums\Channel;

/**
 * Aufgaben-Sicht: Task-Items kommen per deliver() aus dem planner (Zuweisung an
 * den Nutzer). Alles Gemeinsame steckt in AbstractInboxAssignedQueryService.
 */
class InboxTaskQueryService extends AbstractInboxAssignedQueryService implements InboxTaskQueryContract
{
    protected function channel(): Channel
    {
        return Channel::Task;
    }

    protected function label(): string
    {
        return 'Aufgabe';
    }

    protected function sourceMorph(): string
    {
        return 'planner_task';
    }

    protected function refKey(): string
    {
        return 'task_id';
    }
}
