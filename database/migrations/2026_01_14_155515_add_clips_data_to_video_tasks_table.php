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
            $table->integer('total_clips')->nullable(); // Add total_clips
            $table->string('output_filename')->nullable()->change(); // Make output_filename nullable
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_tasks', function (Blueprint $table) {
            $table->dropColumn('total_clips');
            $table->string('output_filename')->nullable(false)->change(); // Revert to non-nullable (potentially unsafe if data exists)
        });
    }
};
