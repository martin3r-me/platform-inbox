<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_enrichment_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // team_id NULL = global default template (shipped by Inbox itself).
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();

            $table->string('key');                              // stable identifier, e.g. 'standard-mail'
            $table->string('name');
            $table->text('description')->nullable();

            // Which channels does this template apply to? JSON array of strings.
            $table->json('applicable_channels');                // e.g. ["mail"] or ["recording", "meeting"]

            // Output schema as JSON Schema — declares what fields the enrichment produces.
            // Used by the show-view to know how to render results.
            $table->json('output_schema');

            // Prompt template — can contain {placeholders} that the job fills.
            // Available: {body}, {subject}, {sender}, {channel}, {language}, {participants_list}
            $table->longText('prompt_template');

            // Optional system prompt prepended to the LLM call.
            $table->longText('system_prompt')->nullable();

            // Preferred provider:model identifier, e.g. "openai:gpt-4o-mini".
            // Falls back to default provider if unavailable.
            $table->string('preferred_provider', 100)->default('openai:gpt-4o-mini');

            // Template version — bump when prompt or schema changes; old runs are
            // preserved with their original version for audit/comparison.
            $table->unsignedInteger('version')->default(1);

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default_for_channel')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['key', 'team_id'], 'inbox_tpl_key_team_idx');
            $table->index(['is_active', 'is_default_for_channel'], 'inbox_tpl_active_default_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_enrichment_templates');
    }
};
