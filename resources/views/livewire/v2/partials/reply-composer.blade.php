@php
    /**
     * Shared inline reply composer rendered at the bottom of every channel
     * cockpit. Channel-aware fields: mail shows subject, message hides it.
     * Send routes through InboxSendService → ChannelRouter → channel tool,
     * preserving thread context (Outlook /reply, Teams chat send).
     *
     * Variables expected from parent:
     *   $channel — 'mail' | 'message' | …
     */
    $showSubject = ($channel ?? 'mail') === 'mail';
    $placeholder = match ($channel ?? 'mail') {
        'mail' => 'Antwort verfassen …',
        'message' => 'Nachricht tippen …',
        default => 'Antwort …',
    };
@endphp

<div class="shrink-0 border-t border-[var(--ui-border)]/40 bg-white">

    @if(!$this->replyOpen)
        {{-- Closed state — single-line reveal --}}
        <div class="px-6 py-3 flex items-center justify-between">
            <button
                wire:click="openReply"
                class="flex items-center gap-2 text-[12px] text-[var(--ui-secondary)] hover:text-[var(--ui-primary)] transition"
            >
                @svg('heroicon-o-arrow-uturn-left', 'w-4 h-4')
                <span>Antworten</span>
                <kbd class="text-[9px] px-1 py-0.5 rounded border border-[var(--ui-border)]/40 bg-[var(--ui-muted-5)] text-[var(--ui-muted)]">r</kbd>
            </button>
            <div class="text-[10px] text-[var(--ui-muted)]">
                @if($channel === 'mail')
                    Antwort via Outlook · Thread bleibt erhalten
                @elseif($channel === 'message')
                    Antwort via Teams · landet im Chat
                @endif
            </div>
        </div>
    @else
        {{-- Open state — full composer with optional feedback line --}}
        <div class="px-6 py-3 space-y-2">
            <div class="flex items-center justify-between">
                <div class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)]">
                    Antwort
                    @if($channel === 'mail') · Mail @elseif($channel === 'message') · Teams @endif
                </div>
                <div class="flex items-center gap-3 text-[10px] text-[var(--ui-muted)]">
                    <label class="flex items-center gap-1 cursor-pointer">
                        <input type="checkbox" wire:model.live="closeOnSend" class="w-3 h-3" />
                        Nach Versand auf Done
                    </label>
                    <button
                        wire:click="closeReply"
                        class="text-[var(--ui-muted)] hover:text-[var(--ui-primary)]"
                        title="Esc"
                    >
                        @svg('heroicon-o-x-mark', 'w-3.5 h-3.5')
                    </button>
                </div>
            </div>

            @if($showSubject)
                <input
                    type="text"
                    wire:model.defer="replySubject"
                    class="w-full text-[12px] px-3 py-1.5 rounded border border-[var(--ui-border)]/50 focus:border-[var(--ui-primary)]/50 focus:outline-none"
                    placeholder="Betreff"
                />
            @endif

            <textarea
                wire:model.defer="replyBody"
                rows="4"
                class="w-full text-[12.5px] px-3 py-2 rounded border border-[var(--ui-border)]/50 focus:border-[var(--ui-primary)]/50 focus:outline-none leading-relaxed resize-none"
                placeholder="{{ $placeholder }}"
                autofocus
                x-data
                x-init="$nextTick(() => $el.focus())"
            ></textarea>

            @if($this->replyFeedback)
                <div class="text-[11px] px-2 py-1 rounded
                    {{ $this->replyOk
                        ? 'bg-emerald-50 text-emerald-700'
                        : 'bg-rose-50 text-rose-700' }}">
                    {{ $this->replyFeedback }}
                </div>
            @endif

            <div class="flex items-center justify-end gap-2">
                <button
                    wire:click="closeReply"
                    class="px-3 py-1.5 rounded text-[11.5px] text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)] transition"
                >
                    Abbrechen
                </button>
                <button
                    wire:click="sendReply"
                    wire:loading.attr="disabled"
                    class="px-4 py-1.5 rounded text-[11.5px] font-medium text-white bg-[var(--ui-primary)] hover:opacity-90 transition flex items-center gap-1.5 disabled:opacity-50"
                >
                    @svg('heroicon-o-paper-airplane', 'w-3.5 h-3.5')
                    <span wire:loading.remove>Senden</span>
                    <span wire:loading>Sende …</span>
                </button>
            </div>
        </div>
    @endif
</div>
