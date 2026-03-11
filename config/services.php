<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'n8n' => [
        'api_key' => env('N8N_API_KEY', '123456'),
        'render_api_key' => env('N8N_RENDER_API_KEY', env('N8N_API_KEY', '123456')),
        'video_clipper_webhook_url' => env(
            'N8N_VIDEO_CLIPPER_WEBHOOK_URL',
            'https://aidimascndraa.app.n8n.cloud/webhook/video-clipper'
        ),
        'ffmpeg_bin' => env('AUTOCLIPPER_FFMPEG_BIN', 'ffmpeg'),
        'ffprobe_bin' => env('AUTOCLIPPER_FFPROBE_BIN', 'ffprobe'),
        'ytdlp_bin' => env('AUTOCLIPPER_YTDLP_BIN', 'yt-dlp'),
        'python_bin' => env('AUTOCLIPPER_PYTHON_BIN', 'python3'),
        'default_clip_duration' => env('AUTOCLIPPER_CLIP_DURATION', 35),
    ],

];
