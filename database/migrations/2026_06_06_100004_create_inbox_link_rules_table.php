<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_link_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);

            // Match conditions — all nullable → null means "any".
            $table->string('channel')->nullable();              // mail | call | message | meeting
            $table->string('sender_kind')->nullable();          // email | phone | teams
            $table->string('sender_identifier')->nullable();    // exact normalized match
            $table->string('sender_pattern')->nullable();       // LIKE pattern (e.g. "%@kunde.de")
            $table->string('subject_pattern')->nullable();      // LIKE pattern
            $table->string('body_pattern')->nullable();         // LIKE pattern

            // Targets — array of organization_entities.id (one rule can hang on several entities).
            $table->json('entity_ids');

            // Optional automatic post-action.
            $table->string('also_mark_as')->nullable();         // null | 'done'

            // Stats.
            $table->unsignedInteger('matched_count')->default(0);
            $table->timestamp('last_matched_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active', 'priority'], 'inbox_rules_user_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_link_rules');
    }
};
