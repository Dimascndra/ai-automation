<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'youtube_url',
        'status',
        'output_filename',
        'ai_summary',
    ];
}
