<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('video_tasks', function (Blueprint $table) {
            $table->integer('num_clips')->default(1)->after('youtube_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_tasks', function (Blueprint $table) {
            $table->dropColumn('num_clips');
        });
    }
};
