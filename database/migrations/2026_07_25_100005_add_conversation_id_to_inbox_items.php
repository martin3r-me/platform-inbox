<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * conversation_id auf dem Inbox-Item: macht den Mail-Thread zur Einheit —
 * Liste kollabiert pro Thread, Knoten-Links propagieren über den Thread, und neue
 * Mails erben den Knoten. Analog zu ical_uid bei Meetings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inbox_items')) {
            return;
        }

        Schema::table('inbox_items', function (Blueprint $table) {
            if (! Schema::hasColumn('inbox_items', 'conversation_id')) {
                $table->string('conversation_id')->nullable()->after('ical_uid');
                $table->index('conversation_id', 'inbox_items_conversation_id_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inbox_items')) {
            return;
        }

        Schema::table('inbox_items', function (Blueprint $table) {
            if (Schema::hasColumn('inbox_items', 'conversation_id')) {
                $table->dropIndex('inbox_items_conversation_id_idx');
                $table->dropColumn('conversation_id');
            }
        });
    }
};
