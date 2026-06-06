<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_item_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_item_id')->constrained()->cascadeOnDelete();

            // Position in audio.
            $table->unsignedInteger('start_seconds');
            $table->unsignedInteger('end_seconds');

            // Speaker label provided by the source ("A", "B", "spk_1", …).
            // Resolved to a participant on the same item via inbox_item_participants.identifier
            // (where identifier_kind = 'speaker' and identifier = this speaker_label).
            $table->string('speaker_label', 64)->nullable();

            $table->longText('text');

            // Provider's per-segment confidence if exposed.
            $table->decimal('confidence', 3, 2)->nullable();

            $table->timestamps();

            $table->index(['inbox_item_id', 'start_seconds'], 'inbox_seg_item_start_idx');
            $table->index(['inbox_item_id', 'speaker_label'], 'inbox_seg_item_speaker_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_item_segments');
    }
};
