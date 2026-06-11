<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_sender_pulse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Same coordinate system the rest of inbox uses to identify senders.
            $table->string('sender_kind');               // email | phone | teams
            $table->string('sender_identifier');         // normalized

            // 14-day activity histogram as {YYYY-MM-DD: count}. Cheap CSS-only
            // sparkline source for the sender-overview cockpit and stream cards.
            $table->json('pulse_14d');

            // Cached sender-level score = max(item.importance_score) over the
            // last 30d, plus rule-based boosts (carrier, internal, frequency).
            // Drives the 💎 VIP bucket ordering.
            $table->decimal('importance_score', 5, 2)->default(0);

            // {mail: 0.3, message: 0.7, …} — drives the channel-mix bar in the
            // sender overview. Sums to ≤ 1 with audio/call/meeting included.
            $table->json('channel_mix')->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'sender_kind', 'sender_identifier'], 'pulse_user_sender_uq');
            $table->index(['user_id', 'importance_score'], 'pulse_user_score_idx');
            $table->index(['user_id', 'last_seen_at'], 'pulse_user_last_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_sender_pulse');
    }
};
