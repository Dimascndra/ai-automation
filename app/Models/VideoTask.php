<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'youtube_url',
        'num_clips',
        'watermark_path',
        'status',
        'output_filename',
        'ai_summary',
        'total_clips', // Added
    ];

    public function clips()
    {
        return $this->hasMany(VideoClip::class);
    }
}
