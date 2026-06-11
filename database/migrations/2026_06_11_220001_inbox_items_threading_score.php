<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            // Denormalised from the source-model so the V2 stream query can
            // group by sender × thread without a polymorphic join. Filled by
            // the BackfillThreadKey command and at ingest time for new items.
            //   mail     → mail_sessions.conversation_id
            //   message  → message_sessions.chat_id
            //   call/meeting/voice → NULL (one item = its own thread)
            $table->string('thread_key', 191)->nullable()->after('source_id');

            // Pre-computed VIP score per item. Lives at item level because a
            // sender can have both newsletter and personal mail; only the
            // personal one belongs in the VIP bucket. Refreshed by the hourly
            // recompute-scores job; scored_at lets us trigger partial refreshes.
            $table->decimal('importance_score', 5, 2)->default(0)->after('thread_key');
            $table->timestamp('importance_scored_at')->nullable()->after('importance_score');

            // Drives the "Wartet" smart-bucket: set when we send out, cleared
            // when the next inbound on the same thread arrives.
            $table->timestamp('awaiting_reply_since')->nullable()->after('importance_scored_at');

            $table->index(['user_id', 'status', 'thread_key'], 'inbox_items_user_thread_idx');
            $table->index(['user_id', 'importance_score'], 'inbox_items_user_score_idx');
            $table->index('awaiting_reply_since', 'inbox_items_awaiting_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropIndex('inbox_items_user_thread_idx');
            $table->dropIndex('inbox_items_user_score_idx');
            $table->dropIndex('inbox_items_awaiting_idx');
            $table->dropColumn([
                'thread_key',
                'importance_score',
                'importance_scored_at',
                'awaiting_reply_since',
            ]);
        });
    }
};
