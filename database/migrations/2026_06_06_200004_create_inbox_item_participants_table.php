<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_item_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_item_id')->constrained()->cascadeOnDelete();

            // role: sender | recipient | cc | organizer | attendee | speaker
            $table->string('role', 24);

            // Identifier in the underlying source.
            // For email: an email address (lowercased).
            // For phone: digits-only.
            // For Teams: lowercase teams id.
            // For speaker (audio): the source-internal speaker label ("A", "B", "spk_1", …).
            $table->string('identifier')->nullable();
            $table->string('identifier_kind', 24)->nullable();   // email | phone | teams | speaker

            $table->string('display_name')->nullable();

            // Verknüpfte Person-Entity in Organization. Optional — füllt der
            // EnrichmentJob heuristisch, der User kann manuell überschreiben.
            $table->unsignedBigInteger('entity_id')->nullable();    // organization_entities.id — not FK (soft-coupled)
            $table->string('entity_confidence', 8)->nullable();     // low | medium | high

            // Only relevant for audio: voice profile that links recurring speakers across recordings.
            $table->foreignId('voice_profile_id')->nullable();

            $table->timestamps();

            $table->index(['inbox_item_id', 'role'], 'inbox_part_item_role_idx');
            $table->index(['identifier_kind', 'identifier'], 'inbox_part_id_idx');
            $table->index('entity_id', 'inbox_part_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_item_participants');
    }
};
