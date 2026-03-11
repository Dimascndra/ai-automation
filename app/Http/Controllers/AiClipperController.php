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
            'workflow_mode' => 'required|in:manual_url',
            'url' => [
                'required',
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
        $workflowMode = 'manual_url';
        $youtubeUrl = $request->input('url');
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
            'workflow_mode' => 'nullable|in:auto_trending,manual_url',
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
            'source_candidate_queries' => 'nullable|array',
            'source_candidate_queries.*' => 'nullable|string|max:255',
            'ai_enhance' => 'nullable|boolean',
            'ai_model' => 'nullable|string|max:120',
            'auto_clip_find' => 'nullable|boolean',
            'face_tracking' => 'nullable|boolean',
            'face_tracking_required' => 'nullable|boolean',
            'face_tracking_smoothness' => 'nullable|numeric|min:0.5|max:0.98',
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
        $workflowMode = (string) $request->input('workflow_mode', ($isAutoDiscovery ? 'auto_trending' : 'manual_url'));

        $taskId = (int) $request->input('task_id');
        $numClips = (int) $request->input('num_clips', 1);
        $topicHint = $this->normalizeTopicHint((string) $request->input('topic_hint', 'AutoClip'));
        $defaultClipDuration = (int) config('services.n8n.default_clip_duration', 35);
        $defaultClipDuration = max(10, min($defaultClipDuration, 120));
        $clipsPlan = $request->input('clips_plan', []);

        $ffmpegConfigured = (string) config('services.n8n.ffmpeg_bin', 'ffmpeg');
        $ffprobeConfigured = (string) config('services.n8n.ffprobe_bin', 'ffprobe');
        $ytDlpConfigured = (string) config('services.n8n.ytdlp_bin', 'yt-dlp');
        $pythonConfigured = (string) config('services.n8n.python_bin', 'python3');
        $faceTrackingEnabled = $request->boolean('face_tracking', true);
        $faceTrackingRequired = $request->boolean('face_tracking_required', false);
        $faceTrackingSmoothness = (float) $request->input('face_tracking_smoothness', 0.88);
        $faceTrackingSmoothness = max(0.5, min($faceTrackingSmoothness, 0.98));

        $ffmpegBin = $this->resolveBinaryPath($ffmpegConfigured, ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg']);
        $ffprobeBin = $this->resolveBinaryPath($ffprobeConfigured, ['/usr/bin/ffprobe', '/usr/local/bin/ffprobe']);
        $ytDlpBin = $this->resolveBinaryPath($ytDlpConfigured, ['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp']);
        $pythonBin = $this->resolveBinaryPath($pythonConfigured, ['/usr/bin/python3', '/usr/local/bin/python3', '/usr/bin/python']);
        $faceTrackingScript = base_path('scripts/face_track_reframe.py');
        $canUseFaceTracking = $faceTrackingEnabled && $pythonBin && File::exists($faceTrackingScript);

        if ($faceTrackingRequired && !$canUseFaceTracking) {
            return response()->json([
                'error' => 'Face tracking is required but runtime is unavailable.',
                'details' => [
                    'python_bin' => $pythonBin ?: 'not found',
                    'script' => File::exists($faceTrackingScript) ? $faceTrackingScript : 'not found',
                ],
                'hint' => 'Install python3 + opencv-python-headless and deploy scripts/face_track_reframe.py.',
            ], 500);
        }

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

        $aiEnhance = $request->boolean('ai_enhance', true);
        $aiModel = trim((string) $request->input('ai_model', ''));
        $autoClipFind = $request->boolean('auto_clip_find', $workflowMode === 'manual_url');
        $excludeTerms = '-pertandingan -live -highlight -highlights -gol -goal';
        $sourcePodcastQuery = trim((string) $request->input('source_podcast_query', ''));
        $sourceCandidateQueries = $request->input('source_candidate_queries', []);
        $sourceCandidateQueries = is_array($sourceCandidateQueries) ? $sourceCandidateQueries : [];

        if ($isAutoDiscovery) {
            $aiPlan = $this->buildAiDiscoveryPlan(
                topicHint: (string) $topicHint,
                numClips: $numClips,
                clipDuration: $defaultClipDuration,
                excludeTerms: $excludeTerms,
                requestedSourceQuery: $sourcePodcastQuery,
                requestedCandidateQueries: $sourceCandidateQueries,
                requestedClipsPlan: is_array($clipsPlan) ? $clipsPlan : [],
                useAi: $aiEnhance,
                aiModel: $aiModel !== '' ? $aiModel : null,
            );

            $sourcePodcastQuery = $aiPlan['source_podcast_query'];
            $sourceCandidateQueries = $aiPlan['source_candidate_queries'];
            $clipsPlan = $aiPlan['clips_plan'];
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
            $fallbackSourceInputs = [];
            if ($isAutoDiscovery) {
                $searchQuery = $sourcePodcastQuery;
                $searchTopic = trim((string) $request->input('topic_hint', 'teknologi'));
                if ($searchQuery === '') {
                    $searchQuery = 'podcast ' . ($searchTopic !== '' ? $searchTopic : 'teknologi') . ' indonesia';
                }

                if (!preg_match('/\bpodcast\b/i', $searchQuery)) {
                    $searchQuery = 'podcast ' . $searchQuery;
                }

                $candidateQueriesRaw = $sourceCandidateQueries;
                $candidateQueries = [];

                foreach ($candidateQueriesRaw as $candidateQueryRaw) {
                    $candidateQuery = trim((string) $candidateQueryRaw);
                    if ($candidateQuery === '') {
                        continue;
                    }
                    if (!preg_match('/\bpodcast\b/i', $candidateQuery)) {
                        $candidateQuery = 'podcast ' . $candidateQuery;
                    }
                    $candidateQueries[] = trim($candidateQuery . ' ' . $excludeTerms);
                }

                $finalQuery = trim($searchQuery . ' ' . $excludeTerms);
                if (empty($candidateQueries)) {
                    $candidateQueries = [
                        $finalQuery,
                        'podcast ' . ($searchTopic !== '' ? $searchTopic : 'teknologi') . ' indonesia ' . $excludeTerms,
                        'wawancara podcast ' . ($searchTopic !== '' ? $searchTopic : 'teknologi') . ' indonesia ' . $excludeTerms,
                    ];
                } else {
                    array_unshift($candidateQueries, $finalQuery);
                    $candidateQueries = array_values(array_unique($candidateQueries));
                }

                $sourceInput = 'ytsearch5:' . $candidateQueries[0];
                foreach (array_slice($candidateQueries, 1, 5) as $query) {
                    $fallbackSourceInputs[] = 'ytsearch10:' . $query;
                }

                Log::info('Auto podcast source discovery', [
                    'task_id' => $taskId,
                    'query' => $candidateQueries[0],
                    'candidate_queries' => $candidateQueries,
                ]);
            }

            $baseDownloadCommand = [
                $ytDlpBin,
                '--no-playlist',
                '-f',
                'bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/b',
                '--merge-output-format',
                'mp4',
                '--js-runtimes',
                'deno,node',
                '--extractor-args',
                'youtube:player_client=android,web',
                '-o',
                $sourceTemplate,
            ];
            if ($isAutoDiscovery) {
                $baseDownloadCommand = array_merge($baseDownloadCommand, [
                    '-i',
                    '--max-downloads',
                    '1',
                ]);
            }

            $attemptInputs = array_merge([$sourceInput], $fallbackSourceInputs);
            $downloadOutput = '';
            $sourceFiles = [];
            foreach ($attemptInputs as $attemptIndex => $attemptInput) {
                // Clean old partial files before each attempt.
                foreach (glob($tmpDir . DIRECTORY_SEPARATOR . 'source.*') ?: [] as $existing) {
                    if (is_file($existing)) {
                        @unlink($existing);
                    }
                }

                $downloadCommand = $baseDownloadCommand;
                if ($isAutoDiscovery && $attemptIndex === 0) {
                    // First attempt: strict podcast-only title filtering.
                    $downloadCommand = array_merge($downloadCommand, [
                        '--match-title',
                        '(?i)(podcast|obrolan|diskusi|talk\\s?show|wawancara|interview)',
                        '--reject-title',
                        '(?i)(pertandingan|live\\s?stream|highlight|highlights|gol\\b|goal\\b|\\bvs\\b|\\bmatch\\b)',
                    ]);
                }
                $downloadCommand[] = $attemptInput;

                $download = new Process($downloadCommand);
                $download->setTimeout(1800);
                $download->run();

                $attemptOutput = trim($download->getErrorOutput() . "\n" . $download->getOutput());
                $downloadOutput .= ($downloadOutput !== '' ? "\n--- attempt ---\n" : '') . $attemptOutput;

                $sourceFiles = array_values(array_filter(
                    glob($tmpDir . DIRECTORY_SEPARATOR . 'source.*') ?: [],
                    fn ($file) => is_file($file) && !str_ends_with($file, '.part')
                ));

                if (!empty($sourceFiles)) {
                    break;
                }

                if (!$download->isSuccessful() && !$isAutoDiscovery) {
                    return response()->json([
                        'error' => 'Failed to download source video.',
                        'details' => Str::limit($attemptOutput, 1500),
                    ], 500);
                }
            }

            if (empty($sourceFiles) && !empty($downloadOutput)) {
                return response()->json([
                    'error' => 'Failed to download source video.',
                    'details' => Str::limit($downloadOutput, 2500),
                ], 500);
            }

            if (empty($sourceFiles)) {
                return response()->json([
                    'error' => 'Source file not found after download.',
                    'details' => Str::limit($downloadOutput, 1500),
                ], 500);
            }

            $videoCandidates = array_values(array_filter(
                $sourceFiles,
                fn ($file) => preg_match('/\.(mp4|mkv|webm|mov)$/i', $file) === 1
            ));
            $sourceVideo = $videoCandidates[0] ?? $sourceFiles[0];

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

            if (
                $workflowMode === 'manual_url'
                && $autoClipFind
                && (!$request->has('clips_plan') || empty($clipsPlan))
            ) {
                $clipsPlan = $this->buildAiClipPlanFromVideo(
                    sourceVideo: $sourceVideo,
                    ffmpegBin: $ffmpegBin,
                    topicHint: (string) $topicHint,
                    numClips: $numClips,
                    clipDuration: $defaultClipDuration,
                    videoDuration: $videoDuration,
                    aiModel: $aiModel !== '' ? $aiModel : null,
                );
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
            $renderProfiles = $canUseFaceTracking
                ? [
                    ['width' => 1080, 'height' => 1920, 'preset' => 'veryfast', 'crf' => '18', 'threads' => '1'],
                    ['width' => 900, 'height' => 1600, 'preset' => 'veryfast', 'crf' => '18', 'threads' => '1'],
                    ['width' => 720, 'height' => 1280, 'preset' => 'veryfast', 'crf' => '19', 'threads' => '1'],
                ]
                : [
                    ['width' => 1080, 'height' => 1920, 'preset' => 'faster', 'crf' => '18', 'threads' => '2'],
                    ['width' => 900, 'height' => 1600, 'preset' => 'veryfast', 'crf' => '19', 'threads' => '1'],
                    ['width' => 720, 'height' => 1280, 'preset' => 'veryfast', 'crf' => '20', 'threads' => '1'],
                ];
            $subtitleFont = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
            if (!File::exists($subtitleFont)) {
                $subtitleFont = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
            }
            $fontPart = File::exists($subtitleFont) ? 'fontfile=' . $subtitleFont . ':' : '';

            if ($faceTrackingEnabled && !$canUseFaceTracking) {
                Log::warning('Face tracking requested but unavailable. Falling back to center crop.', [
                    'task_id' => $taskId,
                    'python' => $pythonBin,
                    'script' => $faceTrackingScript,
                ]);
            }

            foreach ($segments as $segment) {
                $clipNumber = $segment['clip_number'];
                $tmpOutput = $tmpDir . DIRECTORY_SEPARATOR . 'clip-' . $clipNumber . '.mp4';
                $trackingSource = $tmpDir . DIRECTORY_SEPARATOR . 'tracking-source-' . $clipNumber . '.mp4';
                $subtitleText = $this->escapeDrawtextText((string) ($segment['subtitle_text'] ?? $segment['title'] ?? 'Podcast Clip'));
                $segmentRendered = false;
                $attemptErrors = [];
                $trackingAppliedForSegment = false;
                $segmentSourceAvailable = false;
                $trackingPreparationError = null;
                if ($canUseFaceTracking) {
                    $cutSegment = new Process([
                        $ffmpegBin,
                        '-y',
                        '-ss',
                        (string) round($segment['start_time'], 3),
                        '-to',
                        (string) round($segment['end_time'], 3),
                        '-i',
                        $sourceVideo,
                        '-an',
                        '-c:v',
                        'libx264',
                        '-preset',
                        'veryfast',
                        '-crf',
                        '23',
                        '-threads',
                        '1',
                        '-movflags',
                        '+faststart',
                        $trackingSource,
                    ]);
                    $cutSegment->setTimeout(600);
                    $cutSegment->run();

                    if (!$cutSegment->isSuccessful() || !File::exists($trackingSource) || filesize($trackingSource) <= 0) {
                        $trackingPreparationError = Str::limit(trim($cutSegment->getErrorOutput() ?: $cutSegment->getOutput()), 1200);
                        if ($faceTrackingRequired) {
                            $renderErrors[] = [
                                'clip_number' => $clipNumber,
                                'error' => 'Failed to prepare face tracking source segment.',
                                'details' => $trackingPreparationError,
                            ];
                            continue;
                        }
                    } else {
                        $segmentSourceAvailable = true;
                    }
                }

                foreach ($renderProfiles as $profile) {
                    if (File::exists($tmpOutput)) {
                        File::delete($tmpOutput);
                    }

                    $targetWidth = (int) $profile['width'];
                    $targetHeight = (int) $profile['height'];
                    $portraitFilter = 'scale=' . $targetWidth . ':' . $targetHeight . ':flags=lanczos:force_original_aspect_ratio=increase,crop=' . $targetWidth . ':' . $targetHeight . ',setsar=1';
                    $subtitleFontSize = max(32, (int) round($targetWidth * 0.048));
                    $subtitleBorderWidth = max(2, (int) round($subtitleFontSize * 0.06));
                    $subtitleBoxBorder = max(12, (int) round($subtitleFontSize * 0.35));
                    $subtitleBottom = max(70, (int) round($targetHeight * 0.06));

                    $subtitleFilter = 'drawtext=' . $fontPart
                        . 'text=\'' . $subtitleText . '\':'
                        . 'fontcolor=white:fontsize=' . $subtitleFontSize . ':'
                        . 'borderw=' . $subtitleBorderWidth . ':bordercolor=black@0.85:'
                        . 'box=1:boxcolor=black@0.38:boxborderw=' . $subtitleBoxBorder . ':'
                        . 'line_spacing=8:'
                        . 'x=(w-text_w)/2:y=h-text_h-' . $subtitleBottom;

                    $trackedVideo = $tmpDir . DIRECTORY_SEPARATOR . 'tracked-' . $clipNumber . '-' . $targetWidth . 'x' . $targetHeight . '.avi';
                    $useTrackedVideo = false;
                    $trackingError = $trackingPreparationError;

                    if ($canUseFaceTracking && $segmentSourceAvailable) {
                        if (File::exists($trackedVideo)) {
                            File::delete($trackedVideo);
                        }

                        $track = new Process([
                            $pythonBin,
                            $faceTrackingScript,
                            '--input',
                            $trackingSource,
                            '--output',
                            $trackedVideo,
                            '--width',
                            (string) $targetWidth,
                            '--height',
                            (string) $targetHeight,
                            '--smooth',
                            (string) $faceTrackingSmoothness,
                            '--detect-scale',
                            '0.67',
                            '--detect-interval',
                            '1',
                        ]);
                        $track->setEnv([
                            'OMP_NUM_THREADS' => '1',
                            'OPENBLAS_NUM_THREADS' => '1',
                            'MKL_NUM_THREADS' => '1',
                            'NUMEXPR_NUM_THREADS' => '1',
                            'VECLIB_MAXIMUM_THREADS' => '1',
                            'OPENCV_FOR_THREADS_NUM' => '1',
                            'OPENCV_OPENCL_RUNTIME' => 'disabled',
                        ]);
                        $track->setTimeout(900);
                        $track->run();

                        if ($track->isSuccessful() && File::exists($trackedVideo) && filesize($trackedVideo) > 0) {
                            $useTrackedVideo = true;
                            $trackingAppliedForSegment = true;
                        } else {
                            $trackingError = Str::limit(trim($track->getErrorOutput() ?: $track->getOutput()), 900);
                        }
                    }

                    if ($faceTrackingRequired && !$useTrackedVideo) {
                        $attemptErrors[] = [
                            'resolution' => $targetWidth . 'x' . $targetHeight,
                            'preset' => (string) $profile['preset'],
                            'face_tracking' => false,
                            'face_tracking_error' => $trackingError ?: 'Face tracking output not generated.',
                            'error' => 'Face tracking is required for this run.',
                        ];
                        continue;
                    }

                    $ffmpegCommand = [$ffmpegBin, '-y'];

                    if ($useTrackedVideo) {
                        $ffmpegCommand = array_merge($ffmpegCommand, [
                            '-i',
                            $trackedVideo,
                            '-ss',
                            (string) round($segment['start_time'], 3),
                            '-to',
                            (string) round($segment['end_time'], 3),
                            '-i',
                            $sourceVideo,
                        ]);

                        if ($watermarkPath) {
                            $ffmpegCommand = array_merge($ffmpegCommand, [
                                '-i',
                                $watermarkPath,
                                '-filter_complex',
                                '[0:v]' . $subtitleFilter . '[sub];[2:v]scale=iw*0.2:-1[wm];[sub][wm]overlay=W-w-20:H-h-20[vout]',
                                '-map',
                                '[vout]',
                                '-map',
                                '1:a?',
                            ]);
                        } else {
                            $ffmpegCommand = array_merge($ffmpegCommand, [
                                '-vf',
                                $subtitleFilter,
                                '-map',
                                '0:v:0',
                                '-map',
                                '1:a?',
                            ]);
                        }
                    } else {
                        $ffmpegCommand = array_merge($ffmpegCommand, [
                            '-ss',
                            (string) round($segment['start_time'], 3),
                            '-to',
                            (string) round($segment['end_time'], 3),
                            '-i',
                            $sourceVideo,
                        ]);

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
                    }

                    $ffmpegCommand = array_merge($ffmpegCommand, [
                        '-c:v',
                        'libx264',
                        '-preset',
                        (string) $profile['preset'],
                        '-crf',
                        (string) $profile['crf'],
                        '-threads',
                        (string) $profile['threads'],
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

                    if ($render->isSuccessful() && File::exists($tmpOutput) && filesize($tmpOutput) > 0) {
                        $segment['render_resolution'] = $targetWidth . 'x' . $targetHeight;
                        $segmentRendered = true;
                        break;
                    }

                    $attemptErrors[] = [
                        'resolution' => $targetWidth . 'x' . $targetHeight,
                        'preset' => (string) $profile['preset'],
                        'face_tracking' => $useTrackedVideo,
                        'face_tracking_error' => $trackingError,
                        'error' => Str::limit(trim($render->getErrorOutput() ?: $render->getOutput()), 1200),
                    ];
                }

                if (!$segmentRendered) {
                    $renderErrors[] = [
                        'clip_number' => $clipNumber,
                        'error' => 'All render profiles failed.',
                        'attempts' => $attemptErrors,
                    ];
                    continue;
                }

                $finalFile = $taskDirAbsolute . DIRECTORY_SEPARATOR . 'clip-' . $clipNumber . '.mp4';
                File::copy($tmpOutput, $finalFile);

                $relativePublicPath = $taskDirRelative . '/clip-' . $clipNumber . '.mp4';
                $segment['video_clip_url'] = asset('storage/' . $relativePublicPath);
                $segment['face_tracking'] = $trackingAppliedForSegment ? 'applied' : 'center_crop_fallback';
                $renderedClips[] = $segment;
            }

            if (empty($renderedClips)) {
                return response()->json([
                    'error' => 'Failed to render all requested clips.',
                    'face_tracking' => [
                        'requested' => $faceTrackingEnabled,
                        'required' => $faceTrackingRequired,
                        'available' => $canUseFaceTracking,
                    ],
                    'render_errors' => $renderErrors,
                ], 500);
            }

            return response()->json([
                'task_id' => $taskId,
                'status' => 'completed',
                'callback_url' => $request->input('callback_url'),
                'fact_check_notes' => $request->input('fact_check_notes'),
                'source_podcast_query' => $sourcePodcastQuery,
                'rendered_clips' => $renderedClips,
                'total_clips' => count($renderedClips),
                'face_tracking' => [
                    'requested' => $faceTrackingEnabled,
                    'required' => $faceTrackingRequired,
                    'available' => $canUseFaceTracking,
                    'python' => $pythonBin ?: null,
                    'script' => File::exists($faceTrackingScript) ? $faceTrackingScript : null,
                ],
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

    private function buildAiClipPlanFromVideo(
        string $sourceVideo,
        string $ffmpegBin,
        string $topicHint,
        int $numClips,
        int $clipDuration,
        float $videoDuration,
        ?string $aiModel = null
    ): array {
        $fallback = $this->normalizeClipPlan(
            plan: [],
            topic: $topicHint,
            numClips: $numClips,
            clipDuration: $clipDuration,
            useFixedDuration: false,
            minDuration: 18,
            maxDuration: 95
        );
        $openAiKey = (string) config('services.openai.api_key', '');
        if ($openAiKey === '') {
            return $fallback;
        }

        $transcription = $this->transcribeVideoForClipDiscovery($sourceVideo, $ffmpegBin);
        if (!$transcription) {
            return $fallback;
        }

        $segments = $transcription['segments'] ?? [];
        $segments = is_array($segments) ? $segments : [];
        if (empty($segments) && !empty($transcription['text'])) {
            $segments = $this->buildPseudoSegmentsFromText(
                text: (string) $transcription['text'],
                totalDuration: $videoDuration
            );
        }
        $segments = $this->normalizeTranscriptSegments($segments, $videoDuration);
        if (empty($segments)) {
            return $fallback;
        }

        $segments = array_slice($segments, 0, 350);
        $heuristicFallback = $this->buildHeuristicClipPlanFromTranscriptSegments(
            segments: $segments,
            topic: $topicHint,
            numClips: $numClips,
            clipDuration: $clipDuration,
            videoDuration: $videoDuration
        );
        if (empty($heuristicFallback)) {
            $heuristicFallback = $fallback;
        }
        $model = $aiModel ?: (string) config('services.openai.model', 'gpt-4o-mini');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');

        $systemPrompt = 'You are an expert short-video editor for viral podcast clips. Return ONLY valid JSON.';
        $userPrompt = "From transcript segments below, choose {$numClips} best viral-worthy moments. "
            . "Prioritize: controversial opinions, strong hooks, surprising statements, practical insights, emotional lines. "
            . "Constraints: choose natural conversation windows; duration should be dynamic based on content flow (around 18-95 seconds), avoid intro/outro fluff. "
            . "Return JSON object with key clips_plan (array), each item keys: clip_number,title,subtitle_text,start_time,end_time,duration,reason.\n\n"
            . json_encode([
                'topic' => $topicHint,
                'target_duration' => $clipDuration,
                'segments' => $segments,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::baseUrl($baseUrl)
                ->timeout(35)
                ->withToken($openAiKey)
                ->acceptJson()
                ->post('/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.55,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('AI clip finder request failed', [
                    'status' => $response->status(),
                    'body' => Str::limit((string) $response->body(), 1200),
                ]);
                return $heuristicFallback;
            }

            $content = (string) data_get($response->json(), 'choices.0.message.content', '');
            $aiJson = $this->extractJsonFromText($content);
            if (!$aiJson) {
                return $heuristicFallback;
            }

            $aiClipsRaw = data_get($aiJson, 'clips_plan', []);
            $aiClipsRaw = is_array($aiClipsRaw) ? $aiClipsRaw : [];
            if (count($aiClipsRaw) < $numClips) {
                foreach ($heuristicFallback as $fallbackClip) {
                    if (count($aiClipsRaw) >= $numClips) {
                        break;
                    }
                    $aiClipsRaw[] = $fallbackClip;
                }
            }
            $normalized = $this->normalizeClipPlan(
                plan: $aiClipsRaw,
                topic: $topicHint,
                numClips: $numClips,
                clipDuration: $clipDuration,
                useFixedDuration: false,
                minDuration: 18,
                maxDuration: 95
            );
            $normalized = $this->alignClipPlanToTranscriptSegments(
                clips: $normalized,
                segments: $segments,
                videoDuration: $videoDuration,
                minDuration: 14,
                maxDuration: 95
            );

            return !empty($normalized) ? $normalized : $heuristicFallback;
        } catch (\Throwable $e) {
            Log::warning('AI clip finder exception', ['message' => $e->getMessage()]);
            return $heuristicFallback;
        }
    }

    private function transcribeVideoForClipDiscovery(string $sourceVideo, string $ffmpegBin): ?array
    {
        $openAiKey = (string) config('services.openai.api_key', '');
        if ($openAiKey === '') {
            return null;
        }

        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $transcribeModel = (string) config('services.openai.transcription_model', 'gpt-4o-mini-transcribe');
        $tmpAudio = storage_path('app/tmp/autoclipper/' . Str::uuid()->toString() . '.m4a');

        try {
            File::ensureDirectoryExists(dirname($tmpAudio));
            $extract = new Process([
                $ffmpegBin,
                '-y',
                '-i',
                $sourceVideo,
                '-vn',
                '-ac',
                '1',
                '-ar',
                '16000',
                '-b:a',
                '64k',
                $tmpAudio,
            ]);
            $extract->setTimeout(900);
            $extract->run();

            if (!$extract->isSuccessful() || !File::exists($tmpAudio) || filesize($tmpAudio) <= 0) {
                return null;
            }

            $response = Http::baseUrl($baseUrl)
                ->timeout(180)
                ->withToken($openAiKey)
                ->attach('file', fopen($tmpAudio, 'r'), basename($tmpAudio))
                ->post('/audio/transcriptions', [
                    'model' => $transcribeModel,
                    'response_format' => 'verbose_json',
                ]);

            if ($response->failed()) {
                Log::warning('Audio transcription failed', [
                    'status' => $response->status(),
                    'body' => Str::limit((string) $response->body(), 1200),
                ]);
                return null;
            }

            $json = $response->json();
            if (!is_array($json)) {
                return null;
            }

            return [
                'text' => (string) ($json['text'] ?? ''),
                'segments' => is_array($json['segments'] ?? null) ? $json['segments'] : [],
            ];
        } catch (\Throwable $e) {
            Log::warning('Audio transcription exception', ['message' => $e->getMessage()]);
            return null;
        } finally {
            if (File::exists($tmpAudio)) {
                @unlink($tmpAudio);
            }
        }
    }

    private function buildPseudoSegmentsFromText(string $text, float $totalDuration): array
    {
        $clean = trim(preg_replace('/\s+/', ' ', $text));
        if ($clean === '') {
            return [];
        }

        $chunks = preg_split('/(?<=[\.\!\?])\s+/', $clean) ?: [];
        $chunks = array_values(array_filter(array_map('trim', $chunks), fn ($t) => $t !== ''));
        if (empty($chunks)) {
            $chunks = [mb_substr($clean, 0, 800)];
        }

        $count = count($chunks);
        $slice = max(4.0, $totalDuration / max(1, $count));
        $segments = [];
        foreach ($chunks as $i => $chunk) {
            $start = $i * $slice;
            $end = min($totalDuration, $start + $slice);
            $segments[] = [
                'start' => round($start, 2),
                'end' => round($end, 2),
                'text' => mb_substr($chunk, 0, 280),
            ];
        }

        return $segments;
    }

    private function buildAiDiscoveryPlan(
        string $topicHint,
        int $numClips,
        int $clipDuration,
        string $excludeTerms,
        string $requestedSourceQuery,
        array $requestedCandidateQueries,
        array $requestedClipsPlan,
        bool $useAi,
        ?string $aiModel = null
    ): array {
        $topic = $this->normalizeTopicHint($topicHint);
        $sourceQuery = $this->normalizePodcastQuery($requestedSourceQuery, $topic, $excludeTerms);

        $defaultCandidates = [
            "podcast {$topic} indonesia full episode",
            "podcast {$topic} indonesia terbaru",
            "podcast close the door {$topic}",
            "podcast denny sumargo {$topic}",
            "podcast najwa shihab {$topic}",
            "podcast vindes {$topic}",
            "wawancara {$topic} podcast indonesia",
        ];

        $candidateSeed = !empty($requestedCandidateQueries) ? $requestedCandidateQueries : $defaultCandidates;
        $candidateQueries = [];
        foreach ($candidateSeed as $candidate) {
            $candidateQueries[] = $this->normalizePodcastQuery((string) $candidate, $topic, $excludeTerms);
        }
        $candidateQueries = array_values(array_unique(array_filter($candidateQueries)));
        $candidateQueries = array_slice($candidateQueries, 0, 10);

        $clipsPlan = $this->normalizeClipPlan($requestedClipsPlan, $topic, $numClips, $clipDuration);

        if (!$useAi) {
            return [
                'source_podcast_query' => $sourceQuery,
                'source_candidate_queries' => $candidateQueries,
                'clips_plan' => $clipsPlan,
            ];
        }

        $openAiKey = (string) config('services.openai.api_key', '');
        if ($openAiKey === '') {
            return [
                'source_podcast_query' => $sourceQuery,
                'source_candidate_queries' => $candidateQueries,
                'clips_plan' => $clipsPlan,
            ];
        }

        $model = $aiModel ?: (string) config('services.openai.model', 'gpt-4o-mini');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');

        $promptPayload = [
            'topic' => $topic,
            'num_clips' => $numClips,
            'clip_duration' => $clipDuration,
            'exclude_terms' => $excludeTerms,
            'base_source_query' => $sourceQuery,
            'base_candidate_queries' => $candidateQueries,
            'base_clips_plan' => $clipsPlan,
        ];

        $systemPrompt = 'You are a viral short-video producer specialized in Indonesian podcast content. Return ONLY valid JSON.';
        $userPrompt = "Optimize this plan for higher trending potential while staying podcast-focused. "
            . "Return JSON with keys: source_podcast_query (string), source_candidate_queries (array max 8), clips_plan (array). "
            . "Each clips_plan item: clip_number, title, subtitle_text, start_time, end_time, duration, reason. "
            . "Do not include non-podcast keywords like pertandingan/live/highlight/gol.\n\n"
            . json_encode($promptPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::baseUrl($baseUrl)
                ->timeout(25)
                ->withToken($openAiKey)
                ->acceptJson()
                ->post('/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.6,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('AI discovery plan request failed', [
                    'status' => $response->status(),
                    'body' => Str::limit((string) $response->body(), 1000),
                ]);
                return [
                    'source_podcast_query' => $sourceQuery,
                    'source_candidate_queries' => $candidateQueries,
                    'clips_plan' => $clipsPlan,
                ];
            }

            $content = (string) data_get($response->json(), 'choices.0.message.content', '');
            $aiJson = $this->extractJsonFromText($content);
            if (!$aiJson) {
                return [
                    'source_podcast_query' => $sourceQuery,
                    'source_candidate_queries' => $candidateQueries,
                    'clips_plan' => $clipsPlan,
                ];
            }

            $aiSourceQuery = $this->normalizePodcastQuery(
                (string) data_get($aiJson, 'source_podcast_query', $sourceQuery),
                $topic,
                $excludeTerms
            );

            $aiCandidateRaw = data_get($aiJson, 'source_candidate_queries', []);
            $aiCandidateRaw = is_array($aiCandidateRaw) ? $aiCandidateRaw : [];
            $aiCandidates = [];
            foreach ($aiCandidateRaw as $candidate) {
                $aiCandidates[] = $this->normalizePodcastQuery((string) $candidate, $topic, $excludeTerms);
            }
            $aiCandidates = array_values(array_unique(array_filter($aiCandidates)));
            if (empty($aiCandidates)) {
                $aiCandidates = $candidateQueries;
            }
            $aiCandidates = array_slice($aiCandidates, 0, 10);

            $aiClipsRaw = data_get($aiJson, 'clips_plan', []);
            $aiClipsRaw = is_array($aiClipsRaw) ? $aiClipsRaw : [];
            $aiClips = $this->normalizeClipPlan($aiClipsRaw, $topic, $numClips, $clipDuration);

            return [
                'source_podcast_query' => $aiSourceQuery,
                'source_candidate_queries' => $aiCandidates,
                'clips_plan' => $aiClips,
            ];
        } catch (\Throwable $e) {
            Log::warning('AI discovery plan exception', ['message' => $e->getMessage()]);
            return [
                'source_podcast_query' => $sourceQuery,
                'source_candidate_queries' => $candidateQueries,
                'clips_plan' => $clipsPlan,
            ];
        }
    }

    private function normalizeClipPlan(
        array $plan,
        string $topic,
        int $numClips,
        int $clipDuration,
        bool $useFixedDuration = true,
        int $minDuration = 20,
        int $maxDuration = 60
    ): array
    {
        $hooks = [
            'MOMEN PALING PANAS',
            'PENDAPAT PALING BERANI',
            'INSIGHT YANG JARANG DIBAHAS',
            'BAGIAN YANG BIKIN MIKIR',
            'KALIMAT PALING MENOHOK',
            'PREDIKSI PALING MENARIK',
        ];
        $offsets = [45, 120, 210, 300, 390, 480, 570, 660, 750, 840];
        $safeDuration = max($minDuration, min($clipDuration, $maxDuration));
        $normalized = [];

        for ($i = 0; $i < $numClips; $i++) {
            $clipNumber = $i + 1;
            $item = $plan[$i] ?? [];
            $hook = $hooks[$i % count($hooks)];

            $start = isset($item['start_time']) && is_numeric($item['start_time'])
                ? (float) $item['start_time']
                : (float) ($offsets[$i] ?? (45 + ($i * $safeDuration)));

            if ($useFixedDuration) {
                $end = isset($item['end_time']) && is_numeric($item['end_time'])
                    ? (float) $item['end_time']
                    : ($start + $safeDuration);
            } else {
                if (isset($item['end_time']) && is_numeric($item['end_time'])) {
                    $end = (float) $item['end_time'];
                } elseif (isset($item['duration']) && is_numeric($item['duration'])) {
                    $rawDuration = (float) $item['duration'];
                    $dynamicDuration = max($minDuration, min($rawDuration, $maxDuration));
                    $end = $start + $dynamicDuration;
                } else {
                    $end = $start + $safeDuration;
                }
            }

            if ($end <= $start) {
                $end = $start + $safeDuration;
            }

            $currentDuration = $end - $start;
            if (!$useFixedDuration) {
                if ($currentDuration < $minDuration) {
                    $end = $start + $minDuration;
                } elseif ($currentDuration > $maxDuration) {
                    $end = $start + $maxDuration;
                }
            }

            $normalized[] = [
                'clip_number' => isset($item['clip_number']) && is_numeric($item['clip_number'])
                    ? (int) $item['clip_number']
                    : $clipNumber,
                'title' => trim((string) ($item['title'] ?? ($topic . ' - ' . $hook))),
                'subtitle_text' => trim((string) ($item['subtitle_text'] ?? ($hook . ': ' . $topic))),
                'start_time' => round($start, 2),
                'end_time' => round($end, 2),
                'duration' => round($end - $start, 2),
                'reason' => trim((string) ($item['reason'] ?? 'Segment dipilih karena hook conversation yang kuat untuk Shorts.')),
            ];
        }

        return $normalized;
    }

    private function normalizeTranscriptSegments(array $segments, float $videoDuration): array
    {
        $safeDuration = max(1.0, $videoDuration);
        $normalized = [];

        foreach ($segments as $index => $segment) {
            if (!is_array($segment)) {
                continue;
            }

            $startRaw = $segment['start'] ?? $segment['start_time'] ?? null;
            $endRaw = $segment['end'] ?? $segment['end_time'] ?? null;
            if (!is_numeric($startRaw) || !is_numeric($endRaw)) {
                continue;
            }

            $start = (float) $startRaw;
            $end = (float) $endRaw;
            if ($end <= $start) {
                continue;
            }

            $start = max(0.0, min($start, max(0.0, $safeDuration - 0.1)));
            $end = max($start + 0.1, min($end, $safeDuration));
            $text = trim((string) ($segment['text'] ?? $segment['transcript'] ?? ''));
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
            if ($text === '') {
                continue;
            }

            $normalized[] = [
                'start' => round($start, 2),
                'end' => round($end, 2),
                'duration' => round($end - $start, 2),
                'text' => $text,
                'index' => (int) $index,
            ];
        }

        usort($normalized, fn ($a, $b) => ($a['start'] <=> $b['start']));

        return array_values($normalized);
    }

    private function scoreTranscriptSegment(string $text, float $start, float $end, float $videoDuration): float
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
        if ($clean === '') {
            return -1.0;
        }

        $score = 0.0;
        $length = mb_strlen($clean);
        $score += min(0.75, $length / 220.0);

        $punctuation = preg_match_all('/[\?\!]/u', $clean, $matches);
        $score += min(0.35, ((int) $punctuation) * 0.08);

        if (preg_match('/\d/u', $clean)) {
            $score += 0.12;
        }

        $positiveKeywords = [
            'kenapa',
            'gimana',
            'bagaimana',
            'masalah',
            'kontrovers',
            'ternyata',
            'fakta',
            'rahasia',
            'insight',
            'strategi',
            'pelajaran',
            'kesalahan',
            'gagal',
            'sukses',
            'uang',
            'bisnis',
            'peluang',
            'viral',
            'penting',
            'wow',
            'serius',
            'kunci',
            'tips',
            'bukti',
            'menarik',
            'jutaan',
        ];
        foreach ($positiveKeywords as $keyword) {
            if (str_contains($clean, $keyword)) {
                $score += 0.08;
            }
        }

        $negativeKeywords = [
            'subscribe',
            'jangan lupa like',
            'terima kasih sudah menonton',
            'sampai jumpa',
            'opening',
            'intro',
            'outro',
            'sponsor',
            'iklan',
            'promosi',
        ];
        foreach ($negativeKeywords as $keyword) {
            if (str_contains($clean, $keyword)) {
                $score -= 0.2;
            }
        }

        $position = $videoDuration > 0.0 ? ($start / max(1.0, $videoDuration)) : 0.5;
        if ($position < 0.05 || $position > 0.95) {
            $score -= 0.35;
        } elseif ($position < 0.1 || $position > 0.9) {
            $score -= 0.12;
        }

        $segmentDuration = max(0.1, $end - $start);
        if ($segmentDuration > 12.0) {
            $score += 0.08;
        }

        return $score;
    }

    private function buildClipTextLabel(string $text, string $topic, int $clipNumber): array
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $text));
        $clean = trim((string) preg_replace('/^[\-\:\,\.\s]+|[\-\:\,\.\s]+$/u', '', $clean));

        if ($clean === '') {
            $fallbackTitle = $topic . ' - Clip ' . $clipNumber;
            return [
                'title' => $fallbackTitle,
                'subtitle_text' => $fallbackTitle,
            ];
        }

        $words = preg_split('/\s+/u', $clean) ?: [];
        $title = trim(implode(' ', array_slice($words, 0, 8)));
        if ($title === '') {
            $title = $topic . ' - Clip ' . $clipNumber;
        }

        return [
            'title' => Str::limit($title, 80, ''),
            'subtitle_text' => Str::limit($clean, 120),
        ];
    }

    private function overlapRatio(float $aStart, float $aEnd, float $bStart, float $bEnd): float
    {
        $overlap = max(0.0, min($aEnd, $bEnd) - max($aStart, $bStart));
        if ($overlap <= 0.0) {
            return 0.0;
        }
        $shortest = max(0.1, min($aEnd - $aStart, $bEnd - $bStart));

        return $overlap / $shortest;
    }

    private function buildHeuristicClipPlanFromTranscriptSegments(
        array $segments,
        string $topic,
        int $numClips,
        int $clipDuration,
        float $videoDuration
    ): array {
        if (empty($segments)) {
            return [];
        }

        $minDuration = 14.0;
        $maxDuration = 95.0;
        $preferredDuration = (float) max(22, min($clipDuration, 65));

        $scoredSegments = [];
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $start = (float) ($segment['start'] ?? 0.0);
            $end = (float) ($segment['end'] ?? 0.0);
            $text = (string) ($segment['text'] ?? '');
            $score = $this->scoreTranscriptSegment($text, $start, $end, $videoDuration);
            $segment['score'] = $score;
            $scoredSegments[] = $segment;
        }

        $segmentCount = count($scoredSegments);
        if ($segmentCount === 0) {
            return [];
        }

        $candidates = [];
        for ($i = 0; $i < $segmentCount; $i++) {
            $anchor = $scoredSegments[$i];
            if (($anchor['score'] ?? -1.0) < 0.04) {
                continue;
            }

            $left = $i;
            $right = $i;
            $scoreSum = (float) ($anchor['score'] ?? 0.0);
            $included = 1;
            $windowStart = (float) ($anchor['start'] ?? 0.0);
            $windowEnd = (float) ($anchor['end'] ?? $windowStart);

            while (($windowEnd - $windowStart) < $preferredDuration && ($left > 0 || $right < ($segmentCount - 1))) {
                $leftCandidate = null;
                if ($left > 0) {
                    $segment = $scoredSegments[$left - 1];
                    $gap = max(0.0, $windowStart - (float) ($segment['end'] ?? 0.0));
                    $leftCandidate = [
                        'direction' => 'left',
                        'value' => (float) ($segment['score'] ?? 0.0) - ($gap * 0.15),
                        'segment' => $segment,
                    ];
                }

                $rightCandidate = null;
                if ($right < ($segmentCount - 1)) {
                    $segment = $scoredSegments[$right + 1];
                    $gap = max(0.0, (float) ($segment['start'] ?? 0.0) - $windowEnd);
                    $rightCandidate = [
                        'direction' => 'right',
                        'value' => (float) ($segment['score'] ?? 0.0) - ($gap * 0.15),
                        'segment' => $segment,
                    ];
                }

                $pick = null;
                if ($leftCandidate && $rightCandidate) {
                    $pick = $leftCandidate['value'] >= $rightCandidate['value'] ? $leftCandidate : $rightCandidate;
                } elseif ($leftCandidate) {
                    $pick = $leftCandidate;
                } elseif ($rightCandidate) {
                    $pick = $rightCandidate;
                }

                if (!$pick) {
                    break;
                }
                if ($pick['value'] < -0.25 && ($windowEnd - $windowStart) >= $minDuration) {
                    break;
                }

                if ($pick['direction'] === 'left') {
                    $left--;
                    $windowStart = (float) ($pick['segment']['start'] ?? $windowStart);
                } else {
                    $right++;
                    $windowEnd = (float) ($pick['segment']['end'] ?? $windowEnd);
                }

                $scoreSum += (float) ($pick['segment']['score'] ?? 0.0);
                $included++;
                if (($windowEnd - $windowStart) >= $maxDuration) {
                    break;
                }
            }

            while (($windowEnd - $windowStart) < $minDuration && ($left > 0 || $right < ($segmentCount - 1))) {
                $leftGap = $left > 0
                    ? max(0.0, $windowStart - (float) ($scoredSegments[$left - 1]['end'] ?? 0.0))
                    : INF;
                $rightGap = $right < ($segmentCount - 1)
                    ? max(0.0, (float) ($scoredSegments[$right + 1]['start'] ?? 0.0) - $windowEnd)
                    : INF;

                if ($leftGap <= $rightGap && $left > 0) {
                    $left--;
                    $segment = $scoredSegments[$left];
                    $windowStart = (float) ($segment['start'] ?? $windowStart);
                    $scoreSum += (float) ($segment['score'] ?? 0.0);
                    $included++;
                } elseif ($right < ($segmentCount - 1)) {
                    $right++;
                    $segment = $scoredSegments[$right];
                    $windowEnd = (float) ($segment['end'] ?? $windowEnd);
                    $scoreSum += (float) ($segment['score'] ?? 0.0);
                    $included++;
                } else {
                    break;
                }
            }

            $windowStart = max(0.0, $windowStart - 0.35);
            $windowEnd = min($videoDuration, $windowEnd + 0.5);
            if ($windowEnd <= $windowStart) {
                continue;
            }

            $windowDuration = $windowEnd - $windowStart;
            if ($windowDuration > $maxDuration) {
                $center = ($windowStart + $windowEnd) / 2.0;
                $windowStart = max(0.0, $center - ($maxDuration / 2.0));
                $windowEnd = min($videoDuration, $windowStart + $maxDuration);
                $windowStart = max(0.0, $windowEnd - $maxDuration);
            } elseif ($windowDuration < $minDuration) {
                $center = ($windowStart + $windowEnd) / 2.0;
                $windowStart = max(0.0, $center - ($minDuration / 2.0));
                $windowEnd = min($videoDuration, $windowStart + $minDuration);
                $windowStart = max(0.0, $windowEnd - $minDuration);
            }

            $windowDuration = max(0.1, $windowEnd - $windowStart);
            $averageScore = $scoreSum / max(1, $included);
            $anchorScore = (float) ($anchor['score'] ?? 0.0);
            $candidateScore = ($anchorScore * 1.3)
                + $averageScore
                + min(0.4, ($windowDuration / max(1.0, $preferredDuration)) * 0.3);

            $candidates[] = [
                'start_time' => round($windowStart, 2),
                'end_time' => round($windowEnd, 2),
                'duration' => round($windowDuration, 2),
                'score' => $candidateScore,
                'text' => (string) ($anchor['text'] ?? ''),
            ];
        }

        if (empty($candidates)) {
            return [];
        }

        usort($candidates, fn ($a, $b) => ($b['score'] <=> $a['score']));

        $selected = [];
        foreach ($candidates as $candidate) {
            $overlapDetected = false;
            foreach ($selected as $existing) {
                $ratio = $this->overlapRatio(
                    (float) $candidate['start_time'],
                    (float) $candidate['end_time'],
                    (float) $existing['start_time'],
                    (float) $existing['end_time']
                );
                if ($ratio > 0.58) {
                    $overlapDetected = true;
                    break;
                }
            }
            if ($overlapDetected) {
                continue;
            }
            $selected[] = $candidate;
            if (count($selected) >= $numClips) {
                break;
            }
        }

        if (count($selected) < $numClips) {
            $fallback = $this->normalizeClipPlan(
                plan: [],
                topic: $topic,
                numClips: $numClips,
                clipDuration: $clipDuration,
                useFixedDuration: false,
                minDuration: 18,
                maxDuration: 95
            );
            foreach ($fallback as $item) {
                if (count($selected) >= $numClips) {
                    break;
                }
                $start = (float) ($item['start_time'] ?? 0.0);
                $end = (float) ($item['end_time'] ?? 0.0);
                $hasOverlap = false;
                foreach ($selected as $existing) {
                    if ($this->overlapRatio($start, $end, (float) $existing['start_time'], (float) $existing['end_time']) > 0.75) {
                        $hasOverlap = true;
                        break;
                    }
                }
                if ($hasOverlap) {
                    continue;
                }
                $selected[] = [
                    'start_time' => $start,
                    'end_time' => $end,
                    'duration' => max(0.1, $end - $start),
                    'score' => -0.5,
                    'text' => (string) ($item['title'] ?? ''),
                ];
            }
        }

        $clips = [];
        foreach (array_values($selected) as $index => $candidate) {
            $clipNumber = $index + 1;
            $label = $this->buildClipTextLabel((string) ($candidate['text'] ?? ''), $topic, $clipNumber);
            $clips[] = [
                'clip_number' => $clipNumber,
                'title' => $label['title'],
                'subtitle_text' => $label['subtitle_text'],
                'start_time' => round((float) $candidate['start_time'], 2),
                'end_time' => round((float) $candidate['end_time'], 2),
                'duration' => round(max(0.1, ((float) $candidate['end_time'] - (float) $candidate['start_time'])), 2),
                'reason' => 'Dipilih karena hook obrolan kuat dan tetap mengikuti alur percakapan natural.',
            ];
        }

        $clips = $this->normalizeClipPlan(
            plan: $clips,
            topic: $topic,
            numClips: $numClips,
            clipDuration: $clipDuration,
            useFixedDuration: false,
            minDuration: 14,
            maxDuration: 95
        );

        return $this->alignClipPlanToTranscriptSegments(
            clips: $clips,
            segments: $segments,
            videoDuration: $videoDuration,
            minDuration: 14,
            maxDuration: 95
        );
    }

    private function alignClipPlanToTranscriptSegments(
        array $clips,
        array $segments,
        float $videoDuration,
        int $minDuration = 14,
        int $maxDuration = 95
    ): array {
        if (empty($clips)) {
            return [];
        }

        $safeVideoDuration = max(1.0, $videoDuration);
        $aligned = [];

        foreach ($clips as $index => $clip) {
            if (!is_array($clip)) {
                continue;
            }

            $start = isset($clip['start_time']) && is_numeric($clip['start_time'])
                ? (float) $clip['start_time']
                : 0.0;
            $end = isset($clip['end_time']) && is_numeric($clip['end_time'])
                ? (float) $clip['end_time']
                : ($start + max(5.0, (float) $minDuration));

            $start = max(0.0, min($start, max(0.0, $safeVideoDuration - 0.1)));
            $end = max($start + 0.1, min($end, $safeVideoDuration));

            $matchingSegments = array_values(array_filter(
                $segments,
                fn ($segment) => (float) ($segment['end'] ?? 0.0) > $start && (float) ($segment['start'] ?? 0.0) < $end
            ));

            if (empty($matchingSegments) && !empty($segments)) {
                $center = ($start + $end) / 2.0;
                usort($segments, function ($a, $b) use ($center) {
                    $aCenter = ((float) ($a['start'] ?? 0.0) + (float) ($a['end'] ?? 0.0)) / 2.0;
                    $bCenter = ((float) ($b['start'] ?? 0.0) + (float) ($b['end'] ?? 0.0)) / 2.0;
                    return abs($aCenter - $center) <=> abs($bCenter - $center);
                });
                $matchingSegments = array_slice($segments, 0, 2);
            }

            if (!empty($matchingSegments)) {
                $start = min(array_map(fn ($segment) => (float) ($segment['start'] ?? 0.0), $matchingSegments));
                $end = max(array_map(fn ($segment) => (float) ($segment['end'] ?? 0.0), $matchingSegments));
                $start = max(0.0, $start - 0.25);
                $end = min($safeVideoDuration, $end + 0.45);
            }

            $duration = $end - $start;
            if ($duration > $maxDuration) {
                $center = ($start + $end) / 2.0;
                $start = max(0.0, $center - ($maxDuration / 2.0));
                $end = min($safeVideoDuration, $start + $maxDuration);
                $start = max(0.0, $end - $maxDuration);
            } elseif ($duration < $minDuration) {
                $center = ($start + $end) / 2.0;
                $start = max(0.0, $center - ($minDuration / 2.0));
                $end = min($safeVideoDuration, $start + $minDuration);
                $start = max(0.0, $end - $minDuration);
            }

            $clip['clip_number'] = is_numeric($clip['clip_number'] ?? null)
                ? (int) $clip['clip_number']
                : ($index + 1);
            $clip['start_time'] = round($start, 2);
            $clip['end_time'] = round(max($start + 0.1, $end), 2);
            $clip['duration'] = round($clip['end_time'] - $clip['start_time'], 2);

            if (trim((string) ($clip['reason'] ?? '')) === '') {
                $clip['reason'] = 'Segment diselaraskan ke batas percakapan agar durasi natural.';
            }

            $aligned[] = $clip;
        }

        usort($aligned, fn ($a, $b) => ((float) $a['start_time'] <=> (float) $b['start_time']));

        foreach ($aligned as $idx => &$clip) {
            $clip['clip_number'] = $idx + 1;
        }
        unset($clip);

        return $aligned;
    }

    private function normalizePodcastQuery(string $query, string $topic, string $excludeTerms): string
    {
        $q = trim($query);
        if ($q === '') {
            $q = "podcast {$topic} indonesia";
        }
        if (!preg_match('/\bpodcast\b/i', $q)) {
            $q = 'podcast ' . $q;
        }
        if (!str_contains(strtolower($q), strtolower($excludeTerms))) {
            $q = trim($q . ' ' . $excludeTerms);
        }

        return preg_replace('/\s+/', ' ', $q) ?: $q;
    }

    private function normalizeTopicHint(string $topicHint): string
    {
        $topic = trim($topicHint);
        if ($topic === '') {
            return 'teknologi';
        }

        $normalized = mb_strtolower($topic);
        $generic = [
            'topik hangat',
            'trending',
            'viral',
            'autoclip',
            'auto clip',
            'auto',
        ];

        if (in_array($normalized, $generic, true)) {
            return 'teknologi';
        }

        return $topic;
    }

    private function extractJsonFromText(string $content): ?array
    {
        $text = trim($content);
        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $text, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        if (preg_match('/(\{.*\})/s', $text, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
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
