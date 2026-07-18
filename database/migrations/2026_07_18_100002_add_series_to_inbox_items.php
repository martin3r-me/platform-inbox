<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B: Serien-Identität bis ins Inbox-Item durchreichen. Damit keyen
 * Dedup, Gruppierung und (Phase C) die Promotion zu einer Meeting-Instanz
 * direkt auf dem Inbox-Item — ohne Rückgriff auf die Connector-Session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->string('series_master_id')->nullable()->after('source_id');
            $table->string('occurrence_type')->nullable()->after('series_master_id');

            $table->index('series_master_id', 'inbox_items_series_master_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropIndex('inbox_items_series_master_idx');
            $table->dropColumn(['series_master_id', 'occurrence_type']);
        });
    }
};
