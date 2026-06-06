<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_sender_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('sender_kind');               // email | phone | teams
            $table->string('sender_identifier');         // normalized

            // subscribed = default (show in inbox)
            // unsubscribed = skip inbox entirely on ingest
            // muted = ingest but mark as ignored (visible in archive/filter)
            $table->string('status')->default('subscribed');

            $table->string('label')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'sender_kind', 'sender_identifier'], 'inbox_sub_user_sender_uq');
            $table->index(['user_id', 'status'], 'inbox_sub_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_sender_subscriptions');
    }
};
