<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_sender_subscriptions', function (Blueprint $table) {
            $table->boolean('is_vip')->default(false)->after('status');
            $table->index(['user_id', 'is_vip'], 'inbox_sub_user_vip_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_sender_subscriptions', function (Blueprint $table) {
            $table->dropIndex('inbox_sub_user_vip_idx');
            $table->dropColumn('is_vip');
        });
    }
};
