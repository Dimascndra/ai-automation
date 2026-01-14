<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoClip extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_task_id',
        'clip_number',
        'title',
        'video_clip_url',
        'start_time',
        'end_time',
        'duration',
        'reason',
    ];

    protected $appends = ['formatted_duration'];

    public function videoTask()
    {
        return $this->belongsTo(VideoTask::class);
    }

    /**
     * Get the duration formatted as MM:SS
     */
    protected function formattedDuration(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                // Ensure duration is treated as a number
                if (!isset($attributes['duration'])) {
                    return '00:00';
                }

                $seconds = floor($attributes['duration']);
                $minutes = floor($seconds / 60);
                $remainingSeconds = $seconds % 60;

                return sprintf('%02d:%02d', $minutes, $remainingSeconds);
            }
        );
    }
}
