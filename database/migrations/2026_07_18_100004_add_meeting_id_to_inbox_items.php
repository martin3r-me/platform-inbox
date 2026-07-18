<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C: Rück-Verweis eines (promoteten) Meeting-Inbox-Items auf seine
 * Meeting-Instanz. Bewusst OHNE FK-Constraint — hält Inbox und Meetings auf
 * DB-Ebene entkoppelt (das Meetings-Modul ist optional). Eine Serie teilt sich
 * eine meeting_id über alle Vorkommen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->unsignedBigInteger('meeting_id')->nullable()->after('occurrence_type');
            $table->index('meeting_id', 'inbox_items_meeting_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropIndex('inbox_items_meeting_idx');
            $table->dropColumn('meeting_id');
        });
    }
};
