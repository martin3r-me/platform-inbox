<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-item relations inside the Inbox. Lets one InboxItem reference
 * another with a typed relation (supplements, transcript_of, reply_to,
 * references). Powers cross-module wiring without forcing producer
 * modules (Whisper, User-Connectors, …) to know about each other —
 * they all bind against the InboxItemLinkContract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_item_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('from_inbox_item_id');
            $table->unsignedBigInteger('to_inbox_item_id');
            // Enum string value from InboxItemRelation. Stored as string
            // rather than DB-level enum so adding new relations is a
            // code-only change.
            $table->string('relation', 64);
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign('from_inbox_item_id')
                ->references('id')->on('inbox_items')
                ->cascadeOnDelete();
            $table->foreign('to_inbox_item_id')
                ->references('id')->on('inbox_items')
                ->cascadeOnDelete();

            // Same (from, to, relation) tuple may not appear twice.
            $table->unique(
                ['from_inbox_item_id', 'to_inbox_item_id', 'relation'],
                'inbox_item_links_unique'
            );

            // Forward + reverse lookups by relation.
            $table->index(['from_inbox_item_id', 'relation'], 'inbox_item_links_from_relation_idx');
            $table->index(['to_inbox_item_id', 'relation'], 'inbox_item_links_to_relation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_item_links');
    }
};
