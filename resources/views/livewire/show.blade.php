<x-ui-page x-data="{}">
    <x-slot name="navbar">
        <x-ui-page-navbar :title="$item->subject ?: '(ohne Betreff)'" :icon="$item->channel?->icon() ?? 'heroicon-o-inbox'" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Inbox', 'href' => route('inbox.index'), 'icon' => 'inbox'],
            ['label' => Str::limit($item->subject ?: $item->sender_identifier ?: 'Eintrag', 40)],
        ]">
            <x-ui-button variant="ghost" size="sm" wire:click="markDone">
                @svg('heroicon-o-check', 'w-4 h-4')
                <span>Erledigt markieren</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Kontext" icon="heroicon-o-information-circle" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-5 bg-[var(--ui-muted-5)]">

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Absender</h3>
                    <p class="text-[12px] text-[var(--ui-secondary)] m-0">
                        <strong>{{ $item->sender_label ?: $item->sender_identifier ?: '—' }}</strong>
                    </p>
                    @if($item->sender_label && $item->sender_identifier)
                        <p class="text-[10px] text-[var(--ui-muted)] mt-1 m-0">{{ $item->sender_identifier }}</p>
                    @endif
                </section>

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Metadaten</h3>
                    <dl class="text-[11px] space-y-1 m-0">
                        <div class="flex justify-between"><dt class="text-[var(--ui-muted)]">Kanal</dt><dd class="text-[var(--ui-secondary)]">{{ $item->channel?->label() }}</dd></div>
                        <div class="flex justify-between"><dt class="text-[var(--ui-muted)]">Richtung</dt><dd class="text-[var(--ui-secondary)]">{{ $item->direction ?: '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-[var(--ui-muted)]">Empfangen</dt><dd class="text-[var(--ui-secondary)]">{{ $item->received_at?->format('d.m.Y H:i') ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-[var(--ui-muted)]">Status</dt><dd class="text-[var(--ui-secondary)]">{{ $item->status?->label() }}</dd></div>
                    </dl>
                </section>

                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Aktionen</h3>
                    <p class="text-[11px] text-[var(--ui-muted)] m-0">
                        Hand-Off zu Helpdesk-Ticket / CRM-Contact / Entity-Verknüpfung folgen in den nächsten Iterationen.
                    </p>
                </section>

            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Verknüpfungen" icon="heroicon-o-link" width="w-80" :defaultOpen="true" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-5">

                @if($this->entityLinkingEnabled)
                    <section>
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Verknüpfte Entities</h3>
                        @if(empty($this->linkedEntities))
                            <p class="text-[11px] text-[var(--ui-muted)] m-0 italic">Noch nichts verknüpft.</p>
                        @else
                            <ul class="space-y-1 list-none p-0 m-0">
                                @foreach($this->linkedEntities as $e)
                                    <li class="flex items-center gap-2 px-2 py-1.5 bg-[var(--ui-muted-5)] rounded text-[11px]">
                                        @svg('heroicon-o-cube', 'w-3.5 h-3.5 text-[var(--ui-muted)] flex-shrink-0')
                                        <div class="flex-1 min-w-0">
                                            <a href="{{ route('organization.entities.show', $e['id']) }}"
                                               class="block text-[var(--ui-secondary)] truncate font-medium hover:underline">{{ $e['name'] }}</a>
                                            @if($e['type'])
                                                <span class="text-[10px] text-[var(--ui-muted)]">{{ $e['type'] }}</span>
                                            @endif
                                        </div>
                                        <button wire:click="unlinkEntity({{ $e['id'] }})" title="Lösen"
                                                class="text-[var(--ui-muted)] hover:text-red-600 flex-shrink-0">
                                            @svg('heroicon-o-x-mark', 'w-3.5 h-3.5')
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>

                    @if($this->entitySuggestion)
                        <section class="p-3 bg-amber-50 border border-amber-200 rounded-lg">
                            <h3 class="text-[10px] font-semibold uppercase tracking-wider text-amber-700 mb-1">Vorschlag</h3>
                            <p class="text-[11px] text-[var(--ui-secondary)] m-0 mb-2">
                                <strong>{{ $this->entitySuggestion['name'] }}</strong>
                                @if($this->entitySuggestion['type'])
                                    <span class="text-[var(--ui-muted)]">· {{ $this->entitySuggestion['type'] }}</span>
                                @endif
                            </p>
                            <p class="text-[10px] text-[var(--ui-muted)] m-0 mb-2 italic">{{ $this->entitySuggestion['reason'] }}</p>
                            <button wire:click="linkEntity({{ $this->entitySuggestion['id'] }})"
                                    class="w-full inline-flex items-center justify-center gap-1 px-2 py-1 text-[11px] bg-amber-600 text-white rounded hover:bg-amber-700">
                                @svg('heroicon-o-link', 'w-3 h-3')
                                Verknüpfen
                            </button>
                        </section>
                    @endif

                    <section>
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Entity hinzufügen</h3>
                        <input
                            type="search"
                            wire:model.live.debounce.300ms="entitySearch"
                            placeholder="Name oder Code..."
                            class="w-full text-[11px] border border-[var(--ui-border)]/60 rounded px-2 py-1 mb-2"
                        />
                        @if(!empty($this->entitySearchResults))
                            <ul class="space-y-1 list-none p-0 m-0 max-h-64 overflow-y-auto">
                                @foreach($this->entitySearchResults as $r)
                                    <li>
                                        <button wire:click="linkEntity({{ $r['id'] }})"
                                                class="w-full flex items-center gap-2 px-2 py-1.5 text-left text-[11px] rounded hover:bg-[var(--ui-muted-5)]">
                                            @svg('heroicon-o-cube', 'w-3.5 h-3.5 text-[var(--ui-muted)] flex-shrink-0')
                                            <span class="flex-1 min-w-0">
                                                <span class="block text-[var(--ui-secondary)] truncate font-medium">{{ $r['name'] }}</span>
                                                @if($r['type'])
                                                    <span class="block text-[10px] text-[var(--ui-muted)]">{{ $r['type'] }}{{ $r['code'] ? ' · ' . $r['code'] : '' }}</span>
                                                @endif
                                            </span>
                                            @svg('heroicon-o-plus', 'w-3.5 h-3.5 text-[var(--ui-muted)] flex-shrink-0')
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @elseif(trim($entitySearch) !== '')
                            <p class="text-[10px] text-[var(--ui-muted)] m-0 italic">Keine Treffer.</p>
                        @endif
                    </section>
                @endif

                <section>
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Original-Session</h3>
                    <dl class="text-[11px] space-y-1 m-0">
                        <div class="flex justify-between gap-2"><dt class="text-[var(--ui-muted)]">Typ</dt><dd class="text-[var(--ui-secondary)] truncate">{{ $item->source_type }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-[var(--ui-muted)]">ID</dt><dd class="text-[var(--ui-secondary)] tabular-nums">{{ $item->source_id }}</dd></div>
                    </dl>
                </section>

            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 min-w-0 min-h-0 flex flex-col overflow-auto p-6 space-y-6 max-w-3xl">
        <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-5">
            @if($item->preview)
                <div class="text-sm text-[var(--ui-secondary)] whitespace-pre-line leading-relaxed">{{ $item->preview }}</div>
            @else
                <p class="text-sm text-[var(--ui-muted)] italic m-0">Keine Vorschau verfügbar.</p>
            @endif
        </div>

        @php
            $channel = $item->channel?->value;
            $canReply = $this->canReply;
        @endphp

        @if($sendFeedback)
            <div class="rounded-lg border p-3 text-sm {{ $sendOk ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">
                @if($sendOk)
                    @svg('heroicon-o-check-circle', 'w-4 h-4 inline mr-1')
                @else
                    @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 inline mr-1')
                @endif
                {{ $sendFeedback }}
            </div>
        @endif

        @if(!$canReply)
            <div class="bg-[var(--ui-muted-5)] border border-dashed border-[var(--ui-border)]/60 rounded-lg p-5 text-center">
                <p class="text-sm text-[var(--ui-muted)] m-0">
                    Für diesen Kanal ist kein Versand-Handler verfügbar.
                </p>
            </div>
        @elseif($channel === 'call')
            <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-5 space-y-3">
                <h2 class="text-sm font-semibold text-[var(--ui-secondary)] flex items-center gap-2">
                    @svg('heroicon-o-phone-arrow-up-right', 'w-4 h-4 text-[var(--ui-primary)]')
                    Rückruf
                </h2>
                <p class="text-[12px] text-[var(--ui-muted)] m-0">
                    Der Rückruf wird über den jeweiligen Connector initiiert — das Gespräch erscheint im normalen Verlauf des Diensts (Sipgate / RingCentral).
                </p>
                <div class="flex justify-end">
                    <x-ui-button variant="primary" size="sm" wire:click="send">
                        @svg('heroicon-o-phone-arrow-up-right', 'w-4 h-4')
                        <span>Jetzt anrufen</span>
                    </x-ui-button>
                </div>
            </div>
        @elseif($channel === 'meeting')
            <div class="bg-[var(--ui-muted-5)] border border-dashed border-[var(--ui-border)]/60 rounded-lg p-5 text-center">
                <p class="text-sm text-[var(--ui-muted)] m-0">
                    Auf Meeting-Einladungen kannst du derzeit nicht direkt aus der Inbox antworten — öffne sie im Original-Kalender.
                </p>
            </div>
        @else
            <div class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-5 space-y-3">
                <h2 class="text-sm font-semibold text-[var(--ui-secondary)] flex items-center gap-2">
                    @svg('heroicon-o-pencil-square', 'w-4 h-4 text-[var(--ui-primary)]')
                    Antworten
                </h2>
                @if($channel === 'mail')
                    <input type="text" wire:model="composeSubject" class="w-full text-sm border border-[var(--ui-border)]/60 rounded px-3 py-2" placeholder="Betreff" />
                @endif
                <textarea wire:model="composeBody" rows="6" class="w-full text-sm border border-[var(--ui-border)]/60 rounded px-3 py-2"
                          placeholder="{{ $channel === 'mail' ? 'Nachricht...' : 'SMS-Text...' }}"></textarea>
                @error('composeBody')
                    <p class="text-[11px] text-red-600">{{ $message }}</p>
                @enderror
                <div class="flex justify-between items-center">
                    <label class="flex items-center gap-2 text-[11px] text-[var(--ui-muted)]">
                        <input type="checkbox" wire:model="closeOnSend" class="rounded border-[var(--ui-border)]/60" />
                        Nach Versand als erledigt markieren
                    </label>
                    <x-ui-button variant="primary" size="sm" wire:click="send">
                        @svg('heroicon-o-paper-airplane', 'w-4 h-4')
                        <span>Senden</span>
                    </x-ui-button>
                </div>
                <p class="text-[11px] text-[var(--ui-muted)] m-0">
                    Versand läuft über den User-Connector — die Antwort landet auch in deinem Original-Dienst.
                </p>
            </div>
        @endif
    </div>
</x-ui-page>
