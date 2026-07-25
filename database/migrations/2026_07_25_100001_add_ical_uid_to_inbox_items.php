<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * iCalUId bis ins Inbox-Item durchreichen — Kollaps-/Promote-Schlüssel für die
 * geteilte Meeting-Instanz (über Serie UND über Beteiligte hinweg identisch).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inbox_items')) {
            return;
        }

        Schema::table('inbox_items', function (Blueprint $table) {
            if (! Schema::hasColumn('inbox_items', 'ical_uid')) {
                $table->string('ical_uid')->nullable()->after('series_master_id');
                $table->index('ical_uid', 'inbox_items_ical_uid_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inbox_items')) {
            return;
        }

        Schema::table('inbox_items', function (Blueprint $table) {
            if (Schema::hasColumn('inbox_items', 'ical_uid')) {
                $table->dropIndex('inbox_items_ical_uid_idx');
                $table->dropColumn('ical_uid');
            }
        });
    }
};
