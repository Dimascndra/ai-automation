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
        Schema::create('video_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_task_id')->constrained()->onDelete('cascade');
            $table->integer('clip_number');
            $table->string('title');
            $table->text('video_clip_url');
            $table->float('start_time');
            $table->float('end_time');
            $table->float('duration');
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_clips');
    }
};
