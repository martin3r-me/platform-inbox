<?php

namespace Platform\Inbox\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Inbox\Enums\InboxItemStatus;
use Platform\Inbox\Jobs\RunEnrichmentJob;
use Platform\Inbox\Models\InboxAutoLinkEvent;
use Platform\Inbox\Models\InboxEnrichmentTemplate;
use Platform\Inbox\Models\InboxItem;
use Platform\Inbox\Models\InboxItemEnrichment;
use Platform\Inbox\Services\InboxEntityLinkService;
use Platform\Inbox\Services\InboxHandoffService;
use Platform\Inbox\Services\InboxRuleEngine;
use Platform\Inbox\Services\InboxSendService;
use Platform\Inbox\Services\InboxVoiceProfileService;

class Show extends Component
{
    public InboxItem $item;
    public string $composeBody = '';
    public string $composeSubject = '';
    public bool $closeOnSend = true;

    public ?string $sendFeedback = null;
    public bool $sendOk = false;

    public string $entitySearch = '';
    public bool $alsoCreateRule = false;

    /** Speaker-label currently being mapped (e.g. "A"). Empty = picker closed. */
    public string $speakerSearchLabel = '';
    public string $speakerSearch = '';

    /** ID of the enrichment the user wants to view (defaults to primary). */
    public ?int $selectedEnrichmentId = null;

    /** Template the user picked in the "neu anreichern"-Selector. */
    public ?int $runTemplateId = null;

    public function mount(InboxItem $item): void
    {
        abort_unless($item->user_id === auth()->id(), 403);

        $this->item = $item;
        $this->composeSubject = $item->subject ? 'Re: ' . $item->subject : '';
    }

    #[Computed]
    public function canReply(): bool
    {
        return app(InboxSendService::class)->canReply($this->item);
    }

    #[Computed]
    public function entityLinkingEnabled(): bool
    {
        return app(InboxEntityLinkService::class)->enabled();
    }

    #[Computed]
    public function linkedEntities(): array
    {
        return app(InboxEntityLinkService::class)->linksFor($this->item);
    }

    #[Computed]
    public function entitySuggestion(): ?array
    {
        $suggest = app(InboxEntityLinkService::class)->suggestForItem($this->item);
        if (!$suggest) {
            return null;
        }
        $linkedIds = array_map(fn ($e) => $e['id'], $this->linkedEntities);
        if (in_array($suggest['id'], $linkedIds, true)) {
            return null;
        }
        return $suggest;
    }

    /**
     * Which currently-linked entities were created by an auto-rule?
     * @return array<int, true>  entity_id → true
     */
    #[Computed]
    public function autoLinkedEntities(): array
    {
        return InboxAutoLinkEvent::query()
            ->where('inbox_item_id', $this->item->id)
            ->pluck('entity_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    #[Computed]
    public function entitySearchResults(): array
    {
        if (trim($this->entitySearch) === '') {
            return [];
        }
        $linkedIds = array_map(fn ($e) => $e['id'], $this->linkedEntities);
        $results = app(InboxEntityLinkService::class)->search(
            $this->entitySearch,
            $this->item->team_id,
        );
        return array_values(array_filter($results, fn ($r) => !in_array($r['id'], $linkedIds, true)));
    }

    public function linkEntity(int $entityId): void
    {
        if (!app(InboxEntityLinkService::class)->link($this->item, $entityId)) {
            return;
        }

        if ($this->alsoCreateRule && $this->item->sender_identifier) {
            try {
                app(InboxRuleEngine::class)->quickRuleFromManualLink($this->item, $entityId);
            } catch (\Throwable $e) {
                \Log::warning('Inbox: quick rule creation failed', ['error' => $e->getMessage()]);
            }
        }

        $this->entitySearch = '';
        $this->alsoCreateRule = false;
        unset($this->linkedEntities, $this->entitySearchResults, $this->entitySuggestion, $this->autoLinkedEntities);
    }

    public function unlinkEntity(int $entityId): void
    {
        if (app(InboxEntityLinkService::class)->unlink($this->item, $entityId)) {
            InboxAutoLinkEvent::where('inbox_item_id', $this->item->id)
                ->where('entity_id', $entityId)
                ->delete();
            unset($this->linkedEntities, $this->entitySearchResults, $this->entitySuggestion, $this->autoLinkedEntities);
        }
    }

    /**
     * All enrichments for this item, freshest first. Used by the switcher.
     */
    #[Computed]
    public function enrichments(): \Illuminate\Support\Collection
    {
        return InboxItemEnrichment::query()
            ->where('inbox_item_id', $this->item->id)
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The currently-displayed enrichment. Defaults to the primary one;
     * the user can switch via promoteEnrichment / selectEnrichment.
     */
    #[Computed]
    public function activeEnrichment(): ?InboxItemEnrichment
    {
        $all = $this->enrichments;
        if ($this->selectedEnrichmentId !== null) {
            $picked = $all->firstWhere('id', $this->selectedEnrichmentId);
            if ($picked) {
                return $picked;
            }
        }
        return $all->firstWhere('is_primary', true) ?? $all->first();
    }

    /**
     * Templates the user can re-run on this item (channel matches + active).
     * @return array<int, array{id:int, name:string, version:int, key:string}>
     */
    #[Computed]
    public function availableTemplates(): array
    {
        $channel = $this->item->channel?->value;
        if (!$channel) {
            return [];
        }
        return InboxEnrichmentTemplate::query()
            ->active()
            ->forChannel($channel)
            ->where(function ($q) {
                $q->whereNull('team_id')->orWhere('team_id', $this->item->team_id);
            })
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'version' => $t->version, 'key' => $t->key])
            ->all();
    }

    public function selectEnrichment(int $enrichmentId): void
    {
        $this->selectedEnrichmentId = $enrichmentId;
    }

    public function promoteEnrichment(int $enrichmentId): void
    {
        $target = InboxItemEnrichment::where('id', $enrichmentId)
            ->where('inbox_item_id', $this->item->id)
            ->first();
        if (!$target) {
            return;
        }
        InboxItemEnrichment::where('inbox_item_id', $this->item->id)
            ->where('id', '!=', $target->id)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
        $target->update(['is_primary' => true]);
        $this->selectedEnrichmentId = $target->id;
        unset($this->enrichments, $this->activeEnrichment);
    }

    #[Computed]
    public function handoffsByActionKey(): array
    {
        return app(InboxHandoffService::class)->handoffsForItem($this->item);
    }

    #[Computed]
    public function plannerAvailable(): bool
    {
        return app(InboxHandoffService::class)->plannerAvailable();
    }

    #[Computed]
    public function helpdeskAvailable(): bool
    {
        return app(InboxHandoffService::class)->helpdeskAvailable();
    }

    /**
     * Item-level handoffs keyed by kind (planner_task → handoff row).
     * The view uses this to render badges on the top-level handoff bar.
     */
    #[Computed]
    public function itemLevelHandoffs(): array
    {
        return app(InboxHandoffService::class)->itemLevelHandoffs($this->item);
    }

    public function handoffActionToPlanner(int $enrichmentId, int $index): void
    {
        $enrichment = InboxItemEnrichment::where('id', $enrichmentId)
            ->where('inbox_item_id', $this->item->id)
            ->first();
        if (!$enrichment) {
            return;
        }
        app(InboxHandoffService::class)->toPlannerTask(
            $this->item,
            $enrichment,
            $index,
            auth()->id(),
        );
        unset($this->handoffsByActionKey);
    }

    public function handoffItemToPlanner(): void
    {
        app(InboxHandoffService::class)->itemToPlannerTask($this->item, auth()->id());
        unset($this->itemLevelHandoffs);
    }

    public function handoffItemToHelpdesk(): void
    {
        app(InboxHandoffService::class)->itemToHelpdeskTicket($this->item, auth()->id());
        unset($this->itemLevelHandoffs);
    }

    public function openSpeakerPicker(string $label): void
    {
        $this->speakerSearchLabel = $label;
        $this->speakerSearch = '';
    }

    public function closeSpeakerPicker(): void
    {
        $this->speakerSearchLabel = '';
        $this->speakerSearch = '';
    }

    #[Computed]
    public function speakerSearchResults(): array
    {
        if ($this->speakerSearchLabel === '' || trim($this->speakerSearch) === '') {
            return [];
        }
        return app(InboxEntityLinkService::class)->search(
            $this->speakerSearch,
            $this->item->team_id,
        );
    }

    public function assignSpeaker(string $label, int $entityId): void
    {
        app(InboxVoiceProfileService::class)->assign($this->item, $label, $entityId);
        $this->closeSpeakerPicker();
    }

    public function clearSpeaker(string $label): void
    {
        app(InboxVoiceProfileService::class)->assign($this->item, $label, null);
    }

    /**
     * Promote an auto-suggested speaker mapping (entity_confidence=medium)
     * to a confirmed one (high) — re-runs the assign path so the underlying
     * voice profile's confirmed_count gets bumped too.
     */
    public function confirmSpeaker(string $label): void
    {
        $participant = $this->item->participants()
            ->where('role', \Platform\Inbox\Models\InboxItemParticipant::ROLE_SPEAKER)
            ->where('identifier', $label)
            ->first();
        if (!$participant || !$participant->entity_id) {
            return;
        }
        app(InboxVoiceProfileService::class)->assign($this->item, $label, (int) $participant->entity_id);
    }

    public function runEnrichment(?int $templateId = null): void
    {
        $templateId = $templateId ?? $this->runTemplateId;
        if (!$templateId) {
            return;
        }
        $template = InboxEnrichmentTemplate::find($templateId);
        if (!$template) {
            return;
        }
        try {
            RunEnrichmentJob::dispatch($this->item->id, $template->id, markPrimary: false);
        } catch (\Throwable $e) {
            \Log::warning('Inbox: re-run dispatch failed', [
                'item_id' => $this->item->id,
                'template_id' => $templateId,
                'error' => $e->getMessage(),
            ]);
        }
        $this->runTemplateId = null;
    }

    public function markDone(): void
    {
        $this->item->update([
            'status' => InboxItemStatus::Done->value,
            'handled_at' => now(),
        ]);
        $this->item->refresh();
    }

    public function send(): void
    {
        $this->sendFeedback = null;

        $channelValue = $this->item->channel?->value;
        $needsBody = in_array($channelValue, ['mail', 'message'], true);
        if ($needsBody) {
            $this->validate([
                'composeBody' => 'required|string|min:1',
            ]);
        }

        $result = app(InboxSendService::class)->sendReply(
            $this->item,
            $this->composeSubject,
            $this->composeBody,
            auth()->user(),
        );

        $this->sendOk = $result['ok'];
        $this->sendFeedback = $result['message'];

        if ($result['ok'] && $this->closeOnSend) {
            $this->item->update([
                'status' => InboxItemStatus::Done->value,
                'handled_at' => now(),
            ]);
            $this->item->refresh();
            $this->composeBody = '';
        }
    }

    public function render()
    {
        return view('inbox::livewire.show')
            ->layout('platform::layouts.app');
    }
}
