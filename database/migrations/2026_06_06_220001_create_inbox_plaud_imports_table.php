<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_plaud_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // Plaud's own file_id is the natural dedup key for re-syncs.
            $table->string('plaud_file_id');

            // Backlink to the inbox item that was created (or already existed).
            $table->foreignId('inbox_item_id')->constrained()->cascadeOnDelete();

            // Convenience fields for forensics — kept small.
            $table->string('device_serial', 64)->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->timestamp('plaud_recorded_at')->nullable();

            $table->timestamps();

            $table->unique(['team_id', 'plaud_file_id'], 'inbox_plaud_team_file_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_plaud_imports');
    }
};
