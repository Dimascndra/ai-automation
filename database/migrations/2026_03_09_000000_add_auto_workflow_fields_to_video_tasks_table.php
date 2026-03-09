<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('video_tasks', function (Blueprint $table) {
            $table->string('workflow_mode')->default('auto_trending')->after('id');
            $table->string('topic_hint')->nullable()->after('youtube_url');
            $table->text('fact_check_notes')->nullable()->after('ai_summary');
            $table->string('source_podcast_query')->nullable()->after('fact_check_notes');
            $table->string('youtube_publish_status')->default('review_pending')->after('source_podcast_query');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_tasks', function (Blueprint $table) {
            $table->dropColumn([
                'workflow_mode',
                'topic_hint',
                'fact_check_notes',
                'source_podcast_query',
                'youtube_publish_status',
            ]);
        });
    }
};
