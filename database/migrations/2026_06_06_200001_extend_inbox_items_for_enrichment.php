<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            // Full content — replaces the role of `preview` for downstream LLM use.
            $table->longText('body')->nullable()->after('preview');

            // Format hint for the enrichment pipeline: 'text' (mail body, message),
            // 'transcript' (audio with speaker labels), 'markdown' (rare).
            $table->string('body_format', 24)->default('text')->after('body');

            // Detected/declared language; helps LLM stay in target language.
            $table->string('language', 8)->nullable()->after('body_format');

            // Audio bookkeeping — actual file persisted via ContextFileReferences
            // (polymorphic, S3-backed via the platform default disk).
            $table->unsignedInteger('audio_duration_seconds')->nullable()->after('language');
            $table->timestamp('audio_recorded_at')->nullable()->after('audio_duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropColumn([
                'body',
                'body_format',
                'language',
                'audio_duration_seconds',
                'audio_recorded_at',
            ]);
        });
    }
};
