<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_auto_link_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('entity_id');                            // org entity id — not FK (soft-coupled)
            $table->foreignId('rule_id')
                ->nullable()
                ->constrained('inbox_link_rules')
                ->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['inbox_item_id', 'entity_id'], 'inbox_ale_item_entity_idx');
            $table->index('rule_id', 'inbox_ale_rule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_auto_link_events');
    }
};
