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

    public function helpdeskAvailable(): bool
    {
        return class_exists(\Platform\Helpdesk\Models\HelpdeskTicket::class)
            && Schema::hasTable('helpdesk_tickets');
    }

    /**
     * Item-level handoff to Planner — task title is the inbox subject,
     * description is the context block (sender, channel, enrichment headline).
     * Idempotent on (item, kind=planner_task, enrichment_id=null, action_item_index=null).
     */
    public function itemToPlannerTask(InboxItem $item, int $userId): ?InboxItemHandoff
    {
        if (!$this->plannerAvailable()) {
            return null;
        }

        $existing = InboxItemHandoff::query()
            ->where('inbox_item_id', $item->id)
            ->whereNull('enrichment_id')
            ->whereNull('action_item_index')
            ->where('kind', InboxItemHandoff::KIND_PLANNER_TASK)
            ->first();
        if ($existing) {
            return $existing;
        }

        $title = mb_substr($item->subject ?: $this->fallbackTitle($item), 0, 200);

        try {
            $task = \Platform\Planner\Models\PlannerTask::create([
                'team_id' => $item->team_id,
                'user_id' => $userId,
                'user_in_charge_id' => $userId,
                'title' => $title,
                'description' => $this->buildItemDescription($item),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Inbox: item-level planner task handoff failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return InboxItemHandoff::create([
            'inbox_item_id' => $item->id,
            'kind' => InboxItemHandoff::KIND_PLANNER_TASK,
            'target_type' => $this->morphAliasFor($task) ?? get_class($task),
            'target_id' => $task->id,
            'enrichment_id' => null,
            'action_item_index' => null,
            'user_id' => $userId,
            'meta' => ['scope' => 'item'],
        ]);
    }

    /**
     * Item-level handoff to Helpdesk — ticket title is the inbox subject,
     * notes contain the context block. Idempotent on (item, kind=helpdesk_ticket,
     * enrichment_id=null, action_item_index=null).
     */
    public function itemToHelpdeskTicket(InboxItem $item, int $userId): ?InboxItemHandoff
    {
        if (!$this->helpdeskAvailable()) {
            return null;
        }

        $existing = InboxItemHandoff::query()
            ->where('inbox_item_id', $item->id)
            ->whereNull('enrichment_id')
            ->whereNull('action_item_index')
            ->where('kind', InboxItemHandoff::KIND_HELPDESK_TICKET)
            ->first();
        if ($existing) {
            return $existing;
        }

        $title = mb_substr($item->subject ?: $this->fallbackTitle($item), 0, 200);

        try {
            $ticket = \Platform\Helpdesk\Models\HelpdeskTicket::create([
                'team_id' => $item->team_id,
                'user_id' => $userId,
                'user_in_charge_id' => $userId,
                'title' => $title,
                'notes' => $this->buildItemDescription($item),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Inbox: helpdesk ticket handoff failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return InboxItemHandoff::create([
            'inbox_item_id' => $item->id,
            'kind' => InboxItemHandoff::KIND_HELPDESK_TICKET,
            'target_type' => $this->morphAliasFor($ticket) ?? get_class($ticket),
            'target_id' => $ticket->id,
            'enrichment_id' => null,
            'action_item_index' => null,
            'user_id' => $userId,
            'meta' => ['scope' => 'item'],
        ]);
    }

    /**
     * All item-level handoffs (action_item_index is null) for the item,
     * keyed by kind so the view can quickly look up "do we already have a
     * Ticket / Task for this whole item?".
     *
     * @return array<string, InboxItemHandoff>
     */
    public function itemLevelHandoffs(InboxItem $item): array
    {
        return InboxItemHandoff::query()
            ->where('inbox_item_id', $item->id)
            ->whereNull('enrichment_id')
            ->whereNull('action_item_index')
            ->get()
            ->keyBy('kind')
            ->all();
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

    /**
     * Context block for item-level handoffs. Combines sender + channel +
     * primary enrichment's headline/tldr — gives the receiving module's user
     * enough to grok without opening the inbox first.
     */
    protected function buildItemDescription(InboxItem $item): string
    {
        $lines = [];
        if ($item->sender_label || $item->sender_identifier) {
            $lines[] = 'Absender: ' . ($item->sender_label ?: $item->sender_identifier);
        }
        if ($item->channel) {
            $lines[] = 'Kanal: ' . $item->channel->value;
        }

        $primary = InboxItemEnrichment::where('inbox_item_id', $item->id)
            ->where('is_primary', true)
            ->where('status', InboxItemEnrichment::STATUS_DONE)
            ->latest()
            ->first();
        if ($primary) {
            $output = $primary->output ?? [];
            if (!empty($output['headline'])) {
                $lines[] = '';
                $lines[] = (string) $output['headline'];
            }
            if (!empty($output['tldr'])) {
                $lines[] = (string) $output['tldr'];
            }
        }

        if (!empty($lines)) {
            $lines[] = '';
        }
        $lines[] = '— Inbox-Item #' . $item->id;

        return implode("\n", $lines);
    }

    protected function fallbackTitle(InboxItem $item): string
    {
        if ($item->sender_label) {
            return 'Aus Inbox: ' . $item->sender_label;
        }
        if ($item->sender_identifier) {
            return 'Aus Inbox: ' . $item->sender_identifier;
        }
        return 'Inbox-Item #' . $item->id;
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
