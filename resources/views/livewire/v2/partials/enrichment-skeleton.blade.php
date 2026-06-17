{{--
    Skeleton/Status-Block für die Enrichment-Box im Cockpit.

    Erwartete Variablen:
      $enrichmentStatus  string|null  done|running|pending|failed|null
      $enrichmentError   string|null  Fehlertext bei failed (optional)

    Rendert nichts wenn null (= es lief noch nichts) oder done (= der
    eigentliche Enrichment-Block übernimmt die Anzeige). Sonst:
      - pending/running → Skeleton mit pulsierenden Balken
      - failed          → kompakte Fehlerzeile mit Errortext
--}}
@php
    $skState = $enrichmentStatus ?? null;
@endphp

@if(in_array($skState, ['pending', 'running'], true))
    <div
        class="rounded-lg border border-[var(--ui-primary)]/20 bg-[var(--ui-primary)]/5 p-4"
        role="status"
        aria-live="polite"
        wire:poll.10s
    >
        <div class="flex items-center gap-2 mb-2 text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-primary)]">
            <span class="inline-block w-2 h-2 rounded-full bg-[var(--ui-primary)] animate-pulse"></span>
            <span>Wird angereichert…</span>
        </div>
        <div class="space-y-1.5">
            <div class="h-2 rounded bg-[var(--ui-primary)]/15 animate-pulse w-3/4"></div>
            <div class="h-2 rounded bg-[var(--ui-primary)]/15 animate-pulse w-5/6"></div>
            <div class="h-2 rounded bg-[var(--ui-primary)]/15 animate-pulse w-1/2"></div>
        </div>
        <p class="text-[10px] text-[var(--ui-muted)] mt-2 m-0">
            Die KI-Zusammenfassung erscheint hier sobald der Job durchgelaufen ist.
        </p>
    </div>
@elseif($skState === 'failed')
    <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-[12px] text-red-800">
        <div class="flex items-center gap-2 mb-1 text-[10px] font-semibold uppercase tracking-wider text-red-700">
            @svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5')
            <span>Enrichment fehlgeschlagen</span>
        </div>
        @if(!empty($enrichmentError))
            <p class="m-0 text-[11px] leading-snug text-red-900/80">{{ $enrichmentError }}</p>
        @endif
    </div>
@endif
