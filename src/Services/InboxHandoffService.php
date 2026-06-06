<?php

namespace Platform\Inbox\Services;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemEnrichment;
use Platform\Inbox\Models\InboxItemHandoff;

/**
 * Routes an inbox-item (or a single action-item inside its enrichment)
 * into another module. Soft-coupled: every target check guards on
 * class_exists + Schema::hasTable so Inbox stays usable when Planner /
 * Helpdesk / CRM aren't installed.
 *
 * Returns null when a handoff can't be created; otherwise the persisted
 * InboxItemHandoff row, which the caller renders as a deep-link.
 */
class InboxHandoffService
{
    public function plannerAvailable(): bool
    {
        return class_exists(\Platform\Planner\Models\PlannerTask::class)
            && Schema::hasTable('planner_tasks');
    }

    /**
     * Turn one action_item from a specific enrichment into a PlannerTask.
     * Idempotent on (item, enrichment, index, kind) via the table unique key.
     */
    public function toPlannerTask(InboxItem $item, InboxItemEnrichment $enrichment, int $index, int $userId): ?InboxItemHandoff
    {
        if (!$this->plannerAvailable()) {
            return null;
        }

        $action = $this->actionItemAt($enrichment, $index);
        if (!$action) {
            return null;
        }

        // Idempotent: existing handoff returned as-is.
        $existing = InboxItemHandoff::query()
            ->where('inbox_item_id', $item->id)
            ->where('enrichment_id', $enrichment->id)
            ->where('action_item_index', $index)
            ->where('kind', InboxItemHandoff::KIND_PLANNER_TASK)
            ->first();
        if ($existing) {
            return $existing;
        }

        $title = (string) ($action['text'] ?? '');
        if ($title === '') {
            return null;
        }
        // Trim to a reasonable task title length.
        $title = mb_strlen($title) > 200 ? mb_substr($title, 0, 197) . '…' : $title;

        $description = $this->buildPlannerTaskDescription($item, $enrichment, $action);

        try {
            $taskClass = \Platform\Planner\Models\PlannerTask::class;
            /** @var \Platform\Planner\Models\PlannerTask $task */
            $task = $taskClass::create([
                'team_id' => $item->team_id,
                'user_id' => $userId,
                'user_in_charge_id' => $userId,
                'title' => $title,
                'description' => $description,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Inbox: planner task handoff failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $morphAlias = $this->morphAliasFor($task) ?? get_class($task);

        return InboxItemHandoff::create([
            'inbox_item_id' => $item->id,
            'kind' => InboxItemHandoff::KIND_PLANNER_TASK,
            'target_type' => $morphAlias,
            'target_id' => $task->id,
            'enrichment_id' => $enrichment->id,
            'action_item_index' => $index,
            'user_id' => $userId,
            'meta' => [
                'action_text' => $title,
                'suggested_owner' => $action['suggested_owner'] ?? null,
                'due_hint' => $action['due_hint'] ?? null,
            ],
        ]);
    }

    /**
     * Existing handoffs for an item, indexed by (enrichment_id, action_item_index).
     * The view uses this to mark already-handed-off action items.
     *
     * @return array<string, InboxItemHandoff>
     */
    public function handoffsForItem(InboxItem $item): array
    {
        return InboxItemHandoff::query()
            ->where('inbox_item_id', $item->id)
            ->get()
            ->mapWithKeys(fn ($h) => [
                ($h->enrichment_id ?? 0) . ':' . ($h->action_item_index ?? -1) . ':' . $h->kind => $h,
            ])
            ->all();
    }

    /**
     * Builds the Planner task description with the inbox context: original
     * subject, sender, sender-suggested owner, due hint, plus a backlink to
     * the inbox item.
     */
    protected function buildPlannerTaskDescription(InboxItem $item, InboxItemEnrichment $enrichment, array $action): string
    {
        $lines = [];

        if (!empty($action['suggested_owner'])) {
            $lines[] = '→ ' . $action['suggested_owner'];
        }
        if (!empty($action['due_hint'])) {
            $lines[] = '⏱ ' . $action['due_hint'];
        }
        if (!empty($lines)) {
            $lines[] = '';
        }

        $lines[] = '— Aus Inbox-Item:';
        if ($item->subject) {
            $lines[] = 'Betreff: ' . $item->subject;
        }
        if ($item->sender_label || $item->sender_identifier) {
            $lines[] = 'Absender: ' . ($item->sender_label ?: $item->sender_identifier);
        }
        if ($item->channel) {
            $lines[] = 'Kanal: ' . $item->channel->value;
        }
        $lines[] = 'Anreicherung: ' . ($enrichment->template_key ?? '–') . ' (' . ($enrichment->provider ?? '–') . ')';

        return implode("\n", $lines);
    }

    protected function actionItemAt(InboxItemEnrichment $enrichment, int $index): ?array
    {
        $output = $enrichment->output ?? [];
        $actions = $output['action_items'] ?? [];
        if (!is_array($actions) || !isset($actions[$index])) {
            return null;
        }
        $raw = $actions[$index];
        if (is_string($raw)) {
            return ['text' => $raw];
        }
        if (!is_array($raw)) {
            return null;
        }
        return $raw;
    }

    protected function morphAliasFor(object $model): ?string
    {
        $map = array_flip(Relation::morphMap());
        $class = get_class($model);
        return $map[$class] ?? null;
    }
}
