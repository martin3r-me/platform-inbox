@php
    $names = $this->entityNames;
    $dry = $this->dryRun;
    $title = $rule?->name ?? 'Neue Regel';
@endphp

<x-ui-page x-data="{}">
    <x-slot name="navbar">
        <x-ui-page-navbar :title="$title" icon="heroicon-o-funnel" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Inbox', 'href' => route('inbox.index'), 'icon' => 'inbox'],
            ['label' => 'Regeln', 'href' => route('inbox.rules.index')],
            ['label' => Str::limit($title, 40)],
        ]">
            <x-ui-button variant="primary" size="sm" wire:click="save">
                @svg('heroicon-o-check', 'w-4 h-4')
                <span>Speichern</span>
            </x-ui-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Übersicht" icon="heroicon-o-information-circle" width="w-72" :defaultOpen="true">
            <div class="p-4 space-y-5 bg-[var(--ui-muted-5)]">
                <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Über</h3>
                    <p class="text-[11px] text-[var(--ui-secondary)] leading-relaxed m-0">
                        Mindestens eine Match-Bedingung muss gesetzt sein. Alle gesetzten Bedingungen werden UND-verknüpft. Patterns: <code>%</code> = beliebige Zeichen, <code>_</code> = ein Zeichen.
                    </p>
                </section>
                @if($rule)
                    <section class="p-3 rounded-lg bg-white border border-[var(--ui-border)]/40 shadow-sm">
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Statistik</h3>
                        <dl class="text-[11px] space-y-1 m-0">
                            <div class="flex justify-between"><dt class="text-[var(--ui-muted)]">Treffer</dt><dd class="text-[var(--ui-secondary)] tabular-nums">{{ $rule->matched_count }}</dd></div>
                            <div class="flex justify-between"><dt class="text-[var(--ui-muted)]">Zuletzt</dt><dd class="text-[var(--ui-secondary)]">{{ $rule->last_matched_at?->diffForHumans() ?? '—' }}</dd></div>
                            <div class="flex justify-between"><dt class="text-[var(--ui-muted)]">Angelegt</dt><dd class="text-[var(--ui-secondary)]">{{ $rule->created_at?->format('d.m.Y') }}</dd></div>
                        </dl>
                    </section>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Dry-Run (30d)" icon="heroicon-o-eye" width="w-80" :defaultOpen="true" storeKey="activityOpen" side="right">
            <div class="p-4 space-y-3">
                @if(!$dry['enabled'])
                    <p class="text-[11px] text-[var(--ui-muted)] m-0">
                        Setze mindestens eine Match-Bedingung, um die Vorschau zu sehen.
                    </p>
                @else
                    <section class="p-3 rounded-lg bg-[var(--ui-muted-5)]">
                        <p class="text-[12px] m-0">
                            <strong class="text-[var(--ui-secondary)]">{{ $dry['total'] }}</strong>
                            von {{ $dry['scanned'] }} Items in den letzten {{ $dry['window_days'] }} Tagen
                            <strong>hätten getroffen</strong>.
                        </p>
                    </section>
                    @if(!empty($dry['sample']))
                        <section>
                            <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2">Beispiele</h3>
                            <ul class="space-y-2 list-none p-0 m-0">
                                @foreach($dry['sample'] as $s)
                                    <li class="p-2 bg-white border border-[var(--ui-border)]/40 rounded text-[11px]">
                                        <div class="font-medium text-[var(--ui-secondary)] truncate">{{ Str::limit($s->subject ?: $s->sender_label ?: $s->sender_identifier ?: '(ohne Betreff)', 60) }}</div>
                                        <div class="text-[10px] text-[var(--ui-muted)] truncate">{{ $s->sender_label ?: $s->sender_identifier }} · {{ $s->received_at?->diffForHumans() }}</div>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @else
                        <p class="text-[11px] text-[var(--ui-muted)] m-0 italic">Kein historisches Item hätte getroffen.</p>
                    @endif
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <div class="flex-1 min-w-0 min-h-0 flex flex-col overflow-auto p-6">
        @if($flash)
            <div class="rounded-lg border p-3 text-sm mb-4 {{ $flashOk ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">
                {{ $flash }}
            </div>
        @endif

        <div class="max-w-3xl space-y-6">

            {{-- Basics --}}
            <section class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-5">
                <h2 class="text-sm font-semibold text-[var(--ui-secondary)] mb-3">Grunddaten</h2>
                <div class="space-y-3">
                    <div>
                        <label class="text-[11px] uppercase text-[var(--ui-muted)]">Name</label>
                        <input type="text" wire:model="form.name" class="w-full text-sm border border-[var(--ui-border)]/60 rounded px-3 py-2" placeholder="z.B. Karen → Projekt Acme" />
                        @error('form.name') <p class="text-[11px] text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-[11px] uppercase text-[var(--ui-muted)]">Priorität</label>
                            <input type="number" wire:model="form.priority" class="w-full text-sm border border-[var(--ui-border)]/60 rounded px-3 py-2" />
                            <p class="text-[10px] text-[var(--ui-muted)] mt-0.5">Niedrigere Zahl = früher geprüft.</p>
                        </div>
                        <div class="flex items-end">
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model="form.is_active" class="rounded border-[var(--ui-border)]/60" />
                                <span class="text-[var(--ui-secondary)]">Aktiv</span>
                            </label>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Match conditions --}}
            <section class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-5">
                <h2 class="text-sm font-semibold text-[var(--ui-secondary)] mb-1">Bedingungen</h2>
                <p class="text-[11px] text-[var(--ui-muted)] mb-3">Leere Felder bedeuten „beliebig". Mindestens eine Bedingung ist Pflicht.</p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-[11px] uppercase text-[var(--ui-muted)]">Kanal</label>
                        <select wire:model.live="form.channel" class="w-full text-sm border border-[var(--ui-border)]/60 rounded px-3 py-2">
                            <option value="">— beliebig —</option>
                            <option value="mail">E-Mail</option>
                            <option value="call">Anruf</option>
                            <option value="message">Nachricht</option>
                            <option value="meeting">Meeting</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[11px] uppercase text-[var(--ui-muted)]">Absender-Kanal</label>
                        <select wire:model.live="form.sender_kind" class="w-full text-sm border border-[var(--ui-border)]/60 rounded px-3 py-2">
                            <option value="">— beliebig —</option>
                            <option value="email">E-Mail</option>
                            <option value="phone">Telefon</option>
                            <option value="teams">Teams</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[11px] uppercase text-[var(--ui-muted)]">Absender (exakt, normalisiert)</label>
                        <input type="text" wire:model.live.debounce.300ms="form.sender_identifier" class="w-full text-sm border border-[var(--ui-border)]/60 rounded px-3 py-2" placeholder="karen@kunde.de" />
                    </div>
                    <div>
                        <label class="text-[11px] uppercase text-[var(--ui-muted)]">Absender (Pattern)</label>
                        <input type="text" wire:model.live.debounce.300ms="form.sender_pattern" class="w-full text-sm border border-[var(--ui-border)]/60 rounded px-3 py-2" placeholder="%@kunde.de" />
                    </div>
                    <div>
                        <label class="text-[11px] uppercase text-[var(--ui-muted)]">Betreff-Pattern</label>
                        <input type="text" wire:model.live.debounce.300ms="form.subject_pattern" class="w-full text-sm border border-[var(--ui-border)]/60 rounded px-3 py-2" placeholder="%Projekt Acme%" />
                    </div>
                    <div>
                        <label class="text-[11px] uppercase text-[var(--ui-muted)]">Vorschau-Pattern</label>
                        <input type="text" wire:model.live.debounce.300ms="form.body_pattern" class="w-full text-sm border border-[var(--ui-border)]/60 rounded px-3 py-2" placeholder="%Statusbericht%" />
                    </div>
                </div>
            </section>

            {{-- Targets --}}
            <section class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-5">
                <h2 class="text-sm font-semibold text-[var(--ui-secondary)] mb-1">Verknüpfen mit Entities</h2>
                <p class="text-[11px] text-[var(--ui-muted)] mb-3">Eine Regel kann an mehrere Entities verknüpfen — z.B. Person + Projekt gleichzeitig.</p>

                @if(empty($form['entity_ids']))
                    <p class="text-[12px] text-amber-600 mb-3">Mindestens eine Ziel-Entity erforderlich.</p>
                @else
                    <div class="flex flex-wrap gap-1.5 mb-3">
                        @foreach($form['entity_ids'] as $eid)
                            <span class="inline-flex items-center gap-1 px-2 py-1 text-[11px] bg-blue-50 text-blue-700 border border-blue-200 rounded">
                                @svg('heroicon-o-cube', 'w-3 h-3')
                                {{ $names[(int)$eid] ?? '#' . $eid }}
                                <button wire:click="removeEntity({{ (int)$eid }})" class="ml-0.5 hover:text-red-600">
                                    @svg('heroicon-o-x-mark', 'w-3 h-3')
                                </button>
                            </span>
                        @endforeach
                    </div>
                @endif
                @error('form.entity_ids') <p class="text-[11px] text-red-600 mb-2">{{ $message }}</p> @enderror

                <input
                    type="search"
                    wire:model.live.debounce.300ms="entitySearch"
                    placeholder="Entity suchen (Name oder Code)..."
                    class="w-full text-sm border border-[var(--ui-border)]/60 rounded px-3 py-2 mb-2"
                />
                @if(!empty($this->entitySearchResults))
                    <ul class="space-y-1 list-none p-0 m-0 max-h-60 overflow-y-auto border border-[var(--ui-border)]/40 rounded">
                        @foreach($this->entitySearchResults as $r)
                            <li>
                                <button wire:click="addEntity({{ $r['id'] }})"
                                        class="w-full flex items-center gap-2 px-3 py-2 text-left text-[12px] hover:bg-[var(--ui-muted-5)]">
                                    @svg('heroicon-o-cube', 'w-3.5 h-3.5 text-[var(--ui-muted)]')
                                    <span class="flex-1 min-w-0">
                                        <span class="block text-[var(--ui-secondary)] truncate font-medium">{{ $r['name'] }}</span>
                                        @if($r['type'])
                                            <span class="block text-[10px] text-[var(--ui-muted)]">{{ $r['type'] }}{{ $r['code'] ? ' · ' . $r['code'] : '' }}</span>
                                        @endif
                                    </span>
                                    @svg('heroicon-o-plus', 'w-3.5 h-3.5 text-[var(--ui-muted)]')
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Post-action --}}
            <section class="bg-white border border-[var(--ui-border)]/40 rounded-lg p-5">
                <h2 class="text-sm font-semibold text-[var(--ui-secondary)] mb-3">Nachgelagerte Aktion</h2>
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox"
                           class="rounded border-[var(--ui-border)]/60"
                           @if($form['also_mark_as'] === 'done') checked @endif
                           wire:click="$set('form.also_mark_as', '{{ $form['also_mark_as'] === 'done' ? '' : 'done' }}')" />
                    <span class="text-[var(--ui-secondary)]">Item nach Auto-Link direkt als erledigt markieren</span>
                </label>
                <p class="text-[11px] text-[var(--ui-muted)] mt-2 m-0">
                    Praktisch für sehr eindeutige Auto-Archive (z.B. „Statusberichte Acme → archivieren").
                </p>
            </section>
        </div>
    </div>
</x-ui-page>
