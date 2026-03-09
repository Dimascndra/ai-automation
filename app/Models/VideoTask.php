<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'workflow_mode',
        'youtube_url',
        'topic_hint',
        'num_clips',
        'watermark_path',
        'status',
        'output_filename',
        'ai_summary',
        'fact_check_notes',
        'source_podcast_query',
        'youtube_publish_status',
        'total_clips',
    ];

    public function clips()
    {
        return $this->hasMany(VideoClip::class);
    }
}
