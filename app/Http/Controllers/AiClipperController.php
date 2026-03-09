<?php

namespace App\Http\Controllers;

use App\Models\VideoClip;
use App\Models\VideoTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

            $httpClient = Http::timeout(30)->asJson();
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
            $task->update([
                'status' => 'failed',
                'ai_summary' => 'Connection failed: ' . $e->getMessage(),
            ]);
            Log::error('Controller Error:', ['message' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Koneksi Gagal: ' . $e->getMessage()], 500);
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

        // Since clips are likely on Cloudinary/External, we redirect or stream.
        // Redirecting is simplest for external URLs.
        return redirect()->away($clip->video_clip_url);
    }
}
