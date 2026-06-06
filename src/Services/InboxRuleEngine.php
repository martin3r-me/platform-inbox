<?php

namespace Platform\Inbox\Services;

use Illuminate\Support\Collection;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Models\InboxAutoLinkEvent;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxLinkRule;

class InboxRuleEngine
{
    public function __construct(protected InboxEntityLinkService $entityLinks) {}

    /**
     * Match an item against all active rules for its user.
     * Returns the rules that fire, in priority order. ALL matching rules
     * apply — overlap is intentional (one mail can legitimately hang on
     * several entities).
     *
     * @return array<int, InboxLinkRule>
     */
    public function findMatchingRules(InboxItem $item): array
    {
        return InboxLinkRule::query()
            ->forUser($item->user_id)
            ->active()
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (InboxLinkRule $r) => $this->matches($r, $item))
            ->values()
            ->all();
    }

    /**
     * Apply all matching rules for an item: link to all target entities
     * (deduped across rules), record auto-link events, update rule stats,
     * and optionally mark the item as done.
     *
     * Returns the entity ids that ended up linked through rules.
     *
     * @return int[]
     */
    public function applyRulesTo(InboxItem $item): array
    {
        if (!$this->entityLinks->enabled()) {
            return [];
        }

        $rules = $this->findMatchingRules($item);
        if (empty($rules)) {
            return [];
        }

        $linkedEntityIds = [];
        $markAsDone = false;

        foreach ($rules as $rule) {
            $rule->increment('matched_count');
            $rule->forceFill(['last_matched_at' => now()])->save();

            if ($rule->also_mark_as === 'done') {
                $markAsDone = true;
            }

            foreach ((array) $rule->entity_ids as $entityId) {
                $entityId = (int) $entityId;
                if ($entityId <= 0 || in_array($entityId, $linkedEntityIds, true)) {
                    continue;
                }
                if ($this->entityLinks->link($item, $entityId)) {
                    $linkedEntityIds[] = $entityId;
                    InboxAutoLinkEvent::create([
                        'inbox_item_id' => $item->id,
                        'entity_id' => $entityId,
                        'rule_id' => $rule->id,
                        'created_at' => now(),
                    ]);
                }
            }
        }

        if ($markAsDone && $item->status !== InboxItemStatus::Done) {
            $item->forceFill([
                'status' => InboxItemStatus::Done->value,
                'handled_at' => now(),
            ])->save();
        }

        return $linkedEntityIds;
    }

    /**
     * Dry-run: which inbox items from the user's recent history WOULD this
     * rule have matched? Used in the rule editor as a foot-gun guard.
     */
    public function dryRun(InboxLinkRule $rule, int $sinceDays = 30, int $sampleLimit = 5): array
    {
        $items = InboxItem::query()
            ->where('user_id', $rule->user_id)
            ->where('received_at', '>=', now()->subDays($sinceDays))
            ->orderByDesc('received_at')
            ->limit(500)
            ->get();

        $matches = $items->filter(fn ($i) => $this->matches($rule, $i))->values();

        return [
            'total' => $matches->count(),
            'scanned' => $items->count(),
            'window_days' => $sinceDays,
            'sample' => $matches->take($sampleLimit)->all(),
        ];
    }

    public function matches(InboxLinkRule $rule, InboxItem $item): bool
    {
        // Channel
        if ($rule->channel !== null && $rule->channel !== ($item->channel?->value)) {
            return false;
        }
        // Sender kind
        if ($rule->sender_kind !== null && $rule->sender_kind !== $item->sender_kind) {
            return false;
        }
        // Sender identifier — exact (normalized) match
        if ($rule->sender_identifier !== null && $rule->sender_identifier !== '') {
            if ($rule->sender_identifier !== ($item->sender_identifier ?? '')) {
                return false;
            }
        }
        // Sender pattern — LIKE
        if ($rule->sender_pattern !== null && $rule->sender_pattern !== '') {
            if (!$this->likeMatch($rule->sender_pattern, (string) ($item->sender_identifier ?? ''))) {
                return false;
            }
        }
        // Subject pattern — LIKE
        if ($rule->subject_pattern !== null && $rule->subject_pattern !== '') {
            if (!$this->likeMatch($rule->subject_pattern, (string) ($item->subject ?? ''))) {
                return false;
            }
        }
        // Body / preview pattern — LIKE
        if ($rule->body_pattern !== null && $rule->body_pattern !== '') {
            if (!$this->likeMatch($rule->body_pattern, (string) ($item->preview ?? ''))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Build an InboxLinkRule prototype from a manual link gesture:
     * "user just hung item X on entity Y — make this a rule for the future".
     */
    public function quickRuleFromManualLink(InboxItem $item, int $entityId): InboxLinkRule
    {
        $label = $item->sender_label ?: $item->sender_identifier ?: 'Absender';
        $name = "Auto-Link: {$label}";

        return InboxLinkRule::create([
            'team_id' => $item->team_id,
            'user_id' => $item->user_id,
            'name' => $name,
            'priority' => 100,
            'is_active' => true,
            'channel' => null,
            'sender_kind' => $item->sender_kind,
            'sender_identifier' => $item->sender_identifier,
            'sender_pattern' => null,
            'subject_pattern' => null,
            'body_pattern' => null,
            'entity_ids' => [$entityId],
            'also_mark_as' => null,
        ]);
    }

    protected function likeMatch(string $pattern, string $value): bool
    {
        // SQL-LIKE → regex: % → .*, _ → .
        $regex = '/^' . str_replace(['\%', '\_'], ['.*', '.'], preg_quote($pattern, '/')) . '$/iu';
        return (bool) preg_match($regex, $value);
    }
}
