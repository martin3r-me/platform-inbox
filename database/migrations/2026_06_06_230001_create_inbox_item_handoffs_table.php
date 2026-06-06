<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_item_handoffs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inbox_item_id')->constrained()->cascadeOnDelete();

            // The kind tells the UI what icon + link to render. The target_type
            // is the morph alias of the produced row (planner_task, ticket, …)
            // so we can render a deep-link without hard-coupling modules.
            $table->string('kind', 32);                            // planner_task | helpdesk_ticket | crm_contact_note | other
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');

            // Which enrichment + which action_item produced this hand-off (null
            // for item-level hand-offs that aren't tied to a single action).
            $table->foreignId('enrichment_id')
                ->nullable()
                ->constrained('inbox_item_enrichments')
                ->nullOnDelete();
            $table->integer('action_item_index')->nullable();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(
                ['inbox_item_id', 'enrichment_id', 'action_item_index', 'kind'],
                'inbox_handoff_dedup_uq',
            );
            $table->index(['target_type', 'target_id'], 'inbox_handoff_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_item_handoffs');
    }
};
