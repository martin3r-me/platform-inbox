<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_item_enrichments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('inbox_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')
                ->nullable()
                ->constrained('inbox_enrichment_templates')
                ->nullOnDelete();

            // Frozen at run time — survives template deletion.
            $table->string('template_key')->nullable();
            $table->unsignedInteger('template_version')->nullable();

            $table->string('status', 24)->default('pending');   // pending | running | done | failed
            $table->string('provider', 100)->nullable();        // openai:gpt-4o-mini, claude:sonnet-4, lemur:default
            $table->string('provider_model')->nullable();

            // The actual structured output produced by the LLM (matches template output_schema).
            $table->json('output')->nullable();

            // Audit + cost tracking — strategic asset (track what wirken hat).
            $table->unsignedInteger('tokens_input')->nullable();
            $table->unsignedInteger('tokens_output')->nullable();
            $table->unsignedInteger('cost_micro_cents')->nullable();   // 1/10000 of a cent — precise for fractional costs
            $table->unsignedInteger('duration_ms')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();

            // Marks the canonical enrichment for the item among multiple runs.
            $table->boolean('is_primary')->default(false);

            $table->text('error_message')->nullable();
            $table->timestamp('run_at')->nullable();

            $table->timestamps();

            $table->index(['inbox_item_id', 'is_primary'], 'inbox_enr_item_primary_idx');
            $table->index(['inbox_item_id', 'template_key'], 'inbox_enr_item_template_idx');
            $table->index(['status', 'created_at'], 'inbox_enr_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_item_enrichments');
    }
};
