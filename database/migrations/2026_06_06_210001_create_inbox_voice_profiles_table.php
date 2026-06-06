<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_voice_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();

            // Link to an organization person-entity. Soft-coupled (no FK
            // because organization module may not be installed).
            $table->unsignedBigInteger('entity_id')->nullable();

            // Friendly name (typically copied from the linked entity).
            $table->string('display_name');

            // Speaker-embedding-vector if the upstream provider supplies one
            // (AssemblyAI/Plaud may, others won't). Stored as JSON for now;
            // when we move to pgvector, this becomes a vector column.
            $table->json('embedding')->nullable();

            // How often this profile was manually confirmed against a recording
            // — higher = more trustworthy for auto-matching.
            $table->unsignedInteger('confirmed_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'entity_id'], 'inbox_vp_team_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_voice_profiles');
    }
};
