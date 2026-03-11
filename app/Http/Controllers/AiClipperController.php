<?php

namespace App\Http\Controllers;

use App\Models\VideoClip;
use App\Models\VideoTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class AiClipperController extends Controller
{
    /**
     * Display the listing of the resource.
     */
    public function index(Request $request)
    {
        $tasks = VideoTask::with('clips')->orderBy('created_at', 'desc')->get();

        if ($request->ajax()) {
            $tasks = $tasks->map(function ($task) {
                // Determine duration logic for display if needed, or rely on frontend to parse clips
                $task->time_ago = $task->created_at->diffForHumans();
                // Append first clip info if exists for preview
                $task->first_clip = $task->clips->first();
                return $task;
            });
            return response()->json($tasks);
        }

        return view('pages.ai-clipper.index', compact('tasks'));
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $task = VideoTask::with('clips')->find($id);

        if (!$task) {
            if (request()->wantsJson() || request()->ajax()) {
                return response()->json(['error' => 'Task not found'], 404);
            }
            return redirect()->route('ai-clipper.index')->with('error', 'Task not found');
        }

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json($task);
        }

        return view('pages.ai-clipper.show', compact('task'));
    }

    /**
     * Polling status for real-time update
     */
    public function checkStatus($id)
    {
        $task = VideoTask::with('clips')->find($id);

        if (!$task) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        return response()->json([
            'status' => $task->status,
            'task' => $task
        ]);
    }

    /**
     * Return the n8n JSON workflow template used by this repo.
     */
    public function workflowJson()
    {
        $path = resource_path('workflows/n8n/autoclipper-youtube-shorts.workflow.json');

        if (!File::exists($path)) {
            return response()->json(['error' => 'Workflow JSON not found'], 404);
        }

        $json = File::get($path);

        return response($json, 200)
            ->header('Content-Type', 'application/json')
            ->header('Content-Disposition', 'inline; filename="autoclipper-youtube-shorts.workflow.json"');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'workflow_mode' => 'required|in:auto_trending,manual_url',
            'url' => [
                'nullable',
                'required_if:workflow_mode,manual_url',
                'url',
                'regex:/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\/.+$/'
            ],
            'topic_hint' => 'nullable|string|max:255',
            'num_clips' => 'nullable|integer|min:1|max:10',
            'watermark' => 'nullable|image|max:2048',
        ]);

        // Rate limiting could be done via middleware 'throttle:5,60' in routes, implemented here manually if needed or via route definition.
        // Assuming route middleware preference for rate limiting.

        $numClips = $request->input('num_clips', 1);
        $workflowMode = $request->input('workflow_mode', 'auto_trending');
        $youtubeUrl = $workflowMode === 'manual_url' ? $request->input('url') : 'auto://trending';
        $watermarkPath = null;
        $watermarkUrl = null;
        $n8nWebhookUrl = config('services.n8n.video_clipper_webhook_url');
        $n8nApiKey = config('services.n8n.api_key');
        $callbackUrl = route('api.ai-clipper.callback');

        // Handle File Upload
        if ($request->hasFile('watermark')) {
            $file = $request->file('watermark');
            $filename = time() . '_watermark.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('watermarks', $filename, 'public');
            $watermarkPath = 'storage/' . $path;
            $watermarkUrl = asset('storage/' . $path);
        }

        // 1. Simpan Data Awal (Status: Processing)
        $task = VideoTask::create([
            'workflow_mode' => $workflowMode,
            'youtube_url' => $youtubeUrl,
            'topic_hint' => $request->input('topic_hint'),
            'num_clips' => $numClips,
            'watermark_path' => $watermarkPath,
            'status' => 'processing',
            'youtube_publish_status' => 'review_pending',
        ]);

        if (!$n8nWebhookUrl) {
            $task->update([
                'status' => 'failed',
                'ai_summary' => 'N8N webhook URL is not configured.',
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'N8N webhook URL belum dikonfigurasi.',
            ], 500);
        }

        // 2. "Tendang" Bola ke n8n
        try {
            $payload = [
                'workflow_mode' => $workflowMode,
                'youtube_url' => $youtubeUrl,
                'topic_hint' => $request->input('topic_hint'),
                'num_clips' => (int) $numClips,
                'watermark_url' => $watermarkUrl,
                'task_id' => $task->id,
                'callback_url' => $callbackUrl,
                'render_upload_url' => route('api.ai-clipper.render-upload'),
                'render_api_key' => config('services.n8n.render_api_key'),
                'workflow_steps' => [
                    'discover_trending_topic',
                    'fact_check_topic',
                    'find_relevant_podcast_sources',
                    'extract_clips_by_target_count',
                    'render_shorts_video',
                    'upload_to_youtube_shorts_review_pending',
                ],
            ];

            Log::info('Sending payload to n8n:', [
                'url' => $n8nWebhookUrl,
                'payload' => $payload,
            ]);

            $httpClient = Http::connectTimeout(10)->timeout(60)->asJson();
            if (!empty($n8nApiKey)) {
                $httpClient = $httpClient->withHeaders([
                    'x-api-key' => $n8nApiKey,
                ]);
            }

            $response = $httpClient->post($n8nWebhookUrl, $payload);

            if ($response->failed()) {
                $responseJson = $response->json();
                $hintMessage = is_array($responseJson) ? ($responseJson['hint'] ?? null) : null;
                $errorMessage = 'Gagal request ke n8n. Status: ' . $response->status();
                if ($response->status() === 404 && $hintMessage) {
                    $errorMessage .= '. ' . $hintMessage;
                }

                $task->update([
                    'status' => 'failed',
                    'ai_summary' => 'N8N request failed with status ' . $response->status(),
                ]);
                Log::error('N8N Error:', ['status' => $response->status(), 'body' => $response->body()]);

                return response()->json([
                    'status' => 'error',
                    'message' => $errorMessage,
                    'debug' => $response->body()
                ], 500);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Video sedang diproses oleh AI',
                'task' => $task
            ]);
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'cURL error 28') && str_contains($n8nWebhookUrl, '/webhook-test/')) {
                $message .= ' | Gunakan URL production webhook `/webhook/...` atau ubah n8n Webhook node ke responseMode `onReceived` agar tidak timeout.';
            }

            $task->update([
                'status' => 'failed',
                'ai_summary' => 'Connection failed: ' . $message,
            ]);
            Log::error('Controller Error:', ['message' => $message]);
            return response()->json(['status' => 'error', 'message' => 'Koneksi Gagal: ' . $message], 500);
        }
    }

    /**
     * Handle callback from n8n.
     */
    public function handleCallback(Request $request)
    {
        $payload = $request->all();

        // n8n can send payload as [ { ... } ] or nested under "body".
        if (isset($payload[0]) && is_array($payload[0])) {
            $payload = $payload[0];
        }

        if (isset($payload['body'])) {
            if (is_array($payload['body'])) {
                $payload = array_merge($payload, $payload['body']);
            } elseif (is_string($payload['body'])) {
                $decodedBody = json_decode($payload['body'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedBody)) {
                    $payload = array_merge($payload, $decodedBody);
                }
            }
            unset($payload['body']);
        }

        if (!isset($payload['status']) && isset($payload['clips'])) {
            $payload['status'] = 'completed';
        }

        if (!isset($payload['total_clips']) && isset($payload['clips']) && is_array($payload['clips'])) {
            $payload['total_clips'] = count($payload['clips']);
        }

        $request->merge($payload);

        // Validation
        $validator = \Illuminate\Support\Facades\Validator::make($payload, [
            'task_id' => 'required|integer|exists:video_tasks,id',
            'status' => 'required|in:completed,failed',
            'total_clips' => 'required_if:status,completed|integer',
            'clips' => 'required_if:status,completed|array',
            'clips.*.clip_number' => 'required|integer',
            'clips.*.title' => 'required|string',
            'clips.*.video_clip_url' => 'required|url',
            // Allow numeric strings or floats
            'clips.*.start_time' => 'required|numeric',
            'clips.*.end_time' => 'required|numeric',
            'clips.*.duration' => 'required|numeric',
            'fact_check_notes' => 'nullable|string',
            'source_podcast_query' => 'nullable|string',
            'youtube_publish_status' => 'nullable|in:review_pending,published,failed',
            'error' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            Log::warning('N8N Callback Validation Failed', [
                'errors' => $validator->errors()->toArray(),
                'payload' => $payload,
                'raw' => $request->getContent(),
            ]);
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 422);
        }

        $task = VideoTask::find($payload['task_id']);

        if (!$task) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        DB::beginTransaction();
        try {
            if ($payload['status'] === 'failed') {
                $task->update([
                    'status' => 'failed',
                    'ai_summary' => $payload['error'] ?? 'Unknown error from N8N',
                    'youtube_publish_status' => $payload['youtube_publish_status'] ?? 'failed',
                ]);
            } else {
                $task->update([
                    'status' => 'completed',
                    'total_clips' => $payload['total_clips'],
                    'ai_summary' => 'Processed ' . $payload['total_clips'] . ' clips.',
                    'fact_check_notes' => $payload['fact_check_notes'] ?? null,
                    'source_podcast_query' => $payload['source_podcast_query'] ?? null,
                    'youtube_publish_status' => $payload['youtube_publish_status'] ?? 'review_pending',
                ]);

                // Insert Clips
                foreach ($payload['clips'] as $clipData) {
                    VideoClip::create([
                        'video_task_id' => $task->id,
                        'clip_number' => $clipData['clip_number'],
                        'title' => $clipData['title'],
                        'video_clip_url' => $clipData['video_clip_url'],
                        'start_time' => $clipData['start_time'],
                        'end_time' => $clipData['end_time'],
                        'duration' => $clipData['duration'],
                        'reason' => $clipData['reason'] ?? null,
                    ]);
                }
            }

            DB::commit();
            return response()->json(['message' => 'Callback processed successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Callback Processing Error: ' . $e->getMessage());
            return response()->json(['error' => 'Internal Server Error'], 500);
        }
    }

    /**
     * Render clips from source video and upload to local server storage.
     */
    public function renderAndUpload(Request $request)
    {
        $expectedApiKey = config('services.n8n.render_api_key');
        if (!empty($expectedApiKey)) {
            $providedApiKey = $request->header('x-render-api-key') ?: $request->header('x-api-key');
            if ($providedApiKey !== $expectedApiKey) {
                return response()->json(['error' => 'Unauthorized render request'], 401);
            }
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'task_id' => 'required|integer|exists:video_tasks,id',
            'youtube_url' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    if (str_starts_with((string) $value, 'auto://')) {
                        return;
                    }

                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        $fail('The youtube url field must be a valid URL or auto:// value.');
                    }
                },
            ],
            'num_clips' => 'nullable|integer|min:1|max:10',
            'topic_hint' => 'nullable|string|max:255',
            'watermark_url' => 'nullable|url',
            'callback_url' => 'nullable|url',
            'fact_check_notes' => 'nullable|string',
            'source_podcast_query' => 'nullable|string',
            'clips_plan' => 'nullable|array',
            'clips_plan.*.clip_number' => 'nullable|integer|min:1',
            'clips_plan.*.title' => 'nullable|string|max:255',
            'clips_plan.*.subtitle_text' => 'nullable|string|max:500',
            'clips_plan.*.start_time' => 'nullable|numeric|min:0',
            'clips_plan.*.end_time' => 'nullable|numeric|min:0',
            'clips_plan.*.duration' => 'nullable|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 422);
        }

        $youtubeUrl = $request->input('youtube_url');
        $isAutoDiscovery = str_starts_with($youtubeUrl, 'auto://');

        $taskId = (int) $request->input('task_id');
        $numClips = (int) $request->input('num_clips', 1);
        $topicHint = $request->input('topic_hint', 'AutoClip');
        $defaultClipDuration = (int) config('services.n8n.default_clip_duration', 35);
        $defaultClipDuration = max(10, min($defaultClipDuration, 120));
        $clipsPlan = $request->input('clips_plan', []);

        $ffmpegConfigured = (string) config('services.n8n.ffmpeg_bin', 'ffmpeg');
        $ffprobeConfigured = (string) config('services.n8n.ffprobe_bin', 'ffprobe');
        $ytDlpConfigured = (string) config('services.n8n.ytdlp_bin', 'yt-dlp');

        $ffmpegBin = $this->resolveBinaryPath($ffmpegConfigured, ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg']);
        $ffprobeBin = $this->resolveBinaryPath($ffprobeConfigured, ['/usr/bin/ffprobe', '/usr/local/bin/ffprobe']);
        $ytDlpBin = $this->resolveBinaryPath($ytDlpConfigured, ['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp']);

        if (!$ffmpegBin || !$ffprobeBin || !$ytDlpBin) {
            return response()->json([
                'error' => 'Required binaries not found on server.',
                'details' => [
                    'ffmpeg' => $ffmpegBin ?: 'not found',
                    'ffprobe' => $ffprobeBin ?: 'not found',
                    'yt_dlp' => $ytDlpBin ?: 'not found',
                ],
                'configured' => [
                    'AUTOCLIPPER_FFMPEG_BIN' => $ffmpegConfigured,
                    'AUTOCLIPPER_FFPROBE_BIN' => $ffprobeConfigured,
                    'AUTOCLIPPER_YTDLP_BIN' => $ytDlpConfigured,
                ],
            ], 500);
        }

        $tmpRoot = storage_path('app/tmp/autoclipper');
        $tmpDir = $tmpRoot . DIRECTORY_SEPARATOR . Str::uuid()->toString();
        $taskDirRelative = 'autoclipper/task-' . $taskId;
        $taskDirAbsolute = storage_path('app/public/' . $taskDirRelative);

        File::ensureDirectoryExists($tmpRoot);
        File::ensureDirectoryExists($tmpDir);
        File::ensureDirectoryExists($taskDirAbsolute);

        try {
            $sourceTemplate = $tmpDir . DIRECTORY_SEPARATOR . 'source.%(ext)s';
            $sourceInput = $youtubeUrl;
            if ($isAutoDiscovery) {
                $searchQuery = trim((string) $request->input('source_podcast_query', ''));
                if ($searchQuery === '') {
                    $searchTopic = trim((string) $request->input('topic_hint', 'teknologi'));
                    $searchQuery = 'podcast ' . ($searchTopic !== '' ? $searchTopic : 'teknologi') . ' indonesia';
                }

                if (!preg_match('/\bpodcast\b/i', $searchQuery)) {
                    $searchQuery = 'podcast ' . $searchQuery;
                }

                $excludeTerms = '-pertandingan -live -highlight -highlights -gol -goal';
                $finalQuery = trim($searchQuery . ' ' . $excludeTerms);
                $sourceInput = 'ytsearch1:' . $finalQuery;

                Log::info('Auto podcast source discovery', [
                    'task_id' => $taskId,
                    'query' => $finalQuery,
                ]);
            }

            $downloadCommand = [
                $ytDlpBin,
                '--no-playlist',
                '-f',
                'bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/b',
            ];

            // In auto mode, keep sources strictly podcast-style and reject match/highlight content.
            if ($isAutoDiscovery) {
                $downloadCommand = array_merge($downloadCommand, [
                    '--match-title',
                    '(?i)(podcast|obrolan|diskusi|talk\\s?show|wawancara|interview)',
                    '--reject-title',
                    '(?i)(pertandingan|live\\s?stream|highlight|highlights|gol\\b|goal\\b|\\bvs\\b|\\bmatch\\b)',
                ]);
            }

            $downloadCommand = array_merge($downloadCommand, [
                '-o',
                $sourceTemplate,
                $sourceInput,
            ]);

            $download = new Process($downloadCommand);
            $download->setTimeout(1800);
            $download->run();

            if (!$download->isSuccessful()) {
                return response()->json([
                    'error' => 'Failed to download source video.',
                    'details' => trim($download->getErrorOutput() ?: $download->getOutput()),
                ], 500);
            }

            $sourceFiles = glob($tmpDir . DIRECTORY_SEPARATOR . 'source.*');
            if (empty($sourceFiles)) {
                return response()->json(['error' => 'Source file not found after download.'], 500);
            }
            $sourceVideo = $sourceFiles[0];

            $probe = new Process([
                $ffprobeBin,
                '-v',
                'error',
                '-show_entries',
                'format=duration',
                '-of',
                'default=noprint_wrappers=1:nokey=1',
                $sourceVideo,
            ]);
            $probe->setTimeout(60);
            $probe->run();
            if (!$probe->isSuccessful()) {
                return response()->json([
                    'error' => 'Failed to inspect source video duration.',
                    'details' => trim($probe->getErrorOutput() ?: $probe->getOutput()),
                ], 500);
            }

            $videoDuration = (float) trim($probe->getOutput());
            if ($videoDuration <= 0) {
                return response()->json(['error' => 'Invalid source video duration.'], 500);
            }

            $watermarkPath = null;
            if ($request->filled('watermark_url')) {
                $wmParsed = parse_url($request->input('watermark_url'));
                $wmPath = $wmParsed['path'] ?? null;
                if ($wmPath && str_starts_with($wmPath, '/storage/')) {
                    $relative = ltrim(substr($wmPath, strlen('/storage/')), '/');
                    $candidate = storage_path('app/public/' . $relative);
                    if (File::exists($candidate)) {
                        $watermarkPath = $candidate;
                    }
                }
            }

            $segments = [];
            $providedPlans = is_array($clipsPlan) ? $clipsPlan : [];
            for ($i = 0; $i < $numClips; $i++) {
                $plan = $providedPlans[$i] ?? [];
                $clipNumber = (int) ($plan['clip_number'] ?? ($i + 1));
                $start = isset($plan['start_time']) ? (float) $plan['start_time'] : ($i * $defaultClipDuration);
                $end = isset($plan['end_time']) ? (float) $plan['end_time'] : min($start + $defaultClipDuration, $videoDuration);

                if ($start >= $videoDuration) {
                    break;
                }

                $end = min($end, $videoDuration);
                if ($end <= $start) {
                    continue;
                }

                $segments[] = [
                    'clip_number' => $clipNumber,
                    'title' => $plan['title'] ?? ($topicHint . ' - Clip ' . $clipNumber),
                    'subtitle_text' => $plan['subtitle_text'] ?? ($plan['title'] ?? ($topicHint . ' - Clip ' . $clipNumber)),
                    'start_time' => $start,
                    'end_time' => $end,
                    'duration' => $end - $start,
                    'reason' => $plan['reason'] ?? 'Auto generated segment by render engine.',
                ];
            }

            if (empty($segments)) {
                return response()->json(['error' => 'No valid clip segments to render.'], 422);
            }

            $renderedClips = [];
            $renderErrors = [];
            $targetWidth = 1080;
            $targetHeight = 1920;
            $portraitFilter = 'scale=' . $targetWidth . ':' . $targetHeight . ':force_original_aspect_ratio=increase,crop=' . $targetWidth . ':' . $targetHeight . ',setsar=1';
            $subtitleFont = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
            if (!File::exists($subtitleFont)) {
                $subtitleFont = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
            }
            $fontPart = File::exists($subtitleFont) ? 'fontfile=' . $subtitleFont . ':' : '';

            foreach ($segments as $segment) {
                $clipNumber = $segment['clip_number'];
                $tmpOutput = $tmpDir . DIRECTORY_SEPARATOR . 'clip-' . $clipNumber . '.mp4';
                $subtitleText = $this->escapeDrawtextText((string) ($segment['subtitle_text'] ?? $segment['title'] ?? 'Podcast Clip'));
                $subtitleFilter = 'drawtext=' . $fontPart
                    . 'text=\'' . $subtitleText . '\':'
                    . 'fontcolor=white:fontsize=52:'
                    . 'borderw=3:bordercolor=black@0.85:'
                    . 'box=1:boxcolor=black@0.38:boxborderw=20:'
                    . 'line_spacing=8:'
                    . 'x=(w-text_w)/2:y=h-text_h-120';

                $ffmpegCommand = [
                    $ffmpegBin,
                    '-y',
                    '-ss',
                    (string) round($segment['start_time'], 3),
                    '-to',
                    (string) round($segment['end_time'], 3),
                    '-i',
                    $sourceVideo,
                ];

                if ($watermarkPath) {
                    $ffmpegCommand = array_merge($ffmpegCommand, [
                        '-i',
                        $watermarkPath,
                        '-filter_complex',
                        '[0:v]' . $portraitFilter . ',' . $subtitleFilter . '[sub];[1:v]scale=iw*0.2:-1[wm];[sub][wm]overlay=W-w-20:H-h-20[vout]',
                        '-map',
                        '[vout]',
                        '-map',
                        '0:a?',
                    ]);
                } else {
                    $ffmpegCommand = array_merge($ffmpegCommand, [
                        '-vf',
                        $portraitFilter . ',' . $subtitleFilter,
                        '-map',
                        '0:v:0',
                        '-map',
                        '0:a?',
                    ]);
                }

                $ffmpegCommand = array_merge($ffmpegCommand, [
                    '-c:v',
                    'libx264',
                    '-preset',
                    'veryfast',
                    '-crf',
                    '23',
                    '-c:a',
                    'aac',
                    '-pix_fmt',
                    'yuv420p',
                    '-movflags',
                    '+faststart',
                    $tmpOutput,
                ]);

                $render = new Process($ffmpegCommand);
                $render->setTimeout(1800);
                $render->run();

                if (!$render->isSuccessful() || !File::exists($tmpOutput)) {
                    $renderErrors[] = [
                        'clip_number' => $clipNumber,
                        'error' => trim($render->getErrorOutput() ?: $render->getOutput()),
                    ];
                    continue;
                }

                $finalFile = $taskDirAbsolute . DIRECTORY_SEPARATOR . 'clip-' . $clipNumber . '.mp4';
                File::copy($tmpOutput, $finalFile);

                $relativePublicPath = $taskDirRelative . '/clip-' . $clipNumber . '.mp4';
                $segment['video_clip_url'] = asset('storage/' . $relativePublicPath);
                $renderedClips[] = $segment;
            }

            if (empty($renderedClips)) {
                return response()->json([
                    'error' => 'Failed to render all requested clips.',
                    'render_errors' => $renderErrors,
                ], 500);
            }

            return response()->json([
                'task_id' => $taskId,
                'status' => 'completed',
                'callback_url' => $request->input('callback_url'),
                'fact_check_notes' => $request->input('fact_check_notes'),
                'source_podcast_query' => $request->input('source_podcast_query'),
                'rendered_clips' => $renderedClips,
                'total_clips' => count($renderedClips),
                'render_errors' => $renderErrors,
            ]);
        } catch (\Throwable $e) {
            Log::error('Render upload error', [
                'task_id' => $taskId,
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'Internal render error',
                'details' => $e->getMessage(),
            ], 500);
        } finally {
            if (File::exists($tmpDir)) {
                File::deleteDirectory($tmpDir);
            }
        }
    }

    private function resolveBinaryPath(string $binary, array $fallbacks = []): ?string
    {
        $candidates = array_values(array_unique(array_filter(array_merge([$binary], $fallbacks))));

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, DIRECTORY_SEPARATOR)) {
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
                continue;
            }

            $pathDirectories = explode(PATH_SEPARATOR, (string) getenv('PATH'));
            $pathDirectories = array_merge($pathDirectories, ['/usr/local/bin', '/usr/bin', '/bin']);
            $pathDirectories = array_values(array_unique(array_filter($pathDirectories)));

            foreach ($pathDirectories as $directory) {
                $fullPath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
                if (is_file($fullPath) && is_executable($fullPath)) {
                    return $fullPath;
                }
            }
        }

        return null;
    }

    private function escapeDrawtextText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'Podcast Clip';
        }

        $text = preg_replace('/\s+/', ' ', $text);
        $text = mb_substr($text, 0, 180);
        $text = wordwrap($text, 28, "\n", true);
        $text = str_replace("\n", '\n', $text);

        return strtr($text, [
            '\\' => '\\\\',
            ':' => '\:',
            "'" => "\\'",
            '%' => '\%',
            ',' => '\,',
            ';' => '\;',
            '[' => '\[',
            ']' => '\]',
        ]);
    }

    /**
     * Download specific clip (Redirect to URL as storage might be external/Cloudinary)
     */
    public function downloadClip($taskId, $clipNumber)
    {
        $clip = VideoClip::where('video_task_id', $taskId)
            ->where('clip_number', $clipNumber)
            ->first();

        if (!$clip) {
            return response()->json(['error' => 'Clip not found'], 404);
        }

        $clipUrl = $clip->video_clip_url;
        $parsed = parse_url($clipUrl);
        $clipPath = $parsed['path'] ?? null;

        // If clip points to local /storage path, stream file via Laravel to bypass web server 403.
        if ($clipPath && str_starts_with($clipPath, '/storage/')) {
            $relativePath = ltrim(substr($clipPath, strlen('/storage/')), '/');
            $localFile = storage_path('app/public/' . $relativePath);

            if (File::exists($localFile)) {
                return response()->file($localFile, [
                    'Content-Type' => 'video/mp4',
                ]);
            }

            return response()->json([
                'error' => 'Clip file not found on server storage.',
                'expected_path' => $relativePath,
                'hint' => 'Workflow n8n harus meng-generate file video nyata dan mengupload ke storage.',
            ], 404);
        }

        return redirect()->away($clip->video_clip_url);
    }
}
