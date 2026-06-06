<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Polymorphic source — points at the underlying user_connector_*_session row.
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');

            // Cached fields for the list view, derived from the source on creation.
            $table->string('channel');                  // mail | call | message | meeting
            $table->string('sender_identifier')->nullable();   // normalized email / digits-only phone / teams id
            $table->string('sender_kind')->nullable();         // email | phone | teams
            $table->string('sender_label')->nullable();        // display name if known
            $table->string('subject')->nullable();
            $table->text('preview')->nullable();
            $table->string('direction')->nullable();           // inbound | outbound

            // Triage state owned by inbox.
            $table->string('status')->default('new');           // new | done | ignored | snoozed | archived
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('handled_at')->nullable();

            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id'], 'inbox_items_source_uq');
            $table->index(['team_id', 'user_id', 'status'], 'inbox_items_user_status_idx');
            $table->index(['user_id', 'received_at'], 'inbox_items_user_received_idx');
            $table->index(['sender_kind', 'sender_identifier'], 'inbox_items_sender_idx');
            $table->index('snoozed_until', 'inbox_items_snoozed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_items');
    }
};
