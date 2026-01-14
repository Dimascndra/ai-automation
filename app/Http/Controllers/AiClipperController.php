<?php

namespace App\Http\Controllers;

use App\Models\VideoClip;
use App\Models\VideoTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            if (request()->wantsJson()) {
                return response()->json(['error' => 'Task not found'], 404);
            }
            return redirect()->route('ai-clipper.index')->with('error', 'Task not found');
        }

        if (request()->wantsJson()) {
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
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'url' => [
                'required',
                'url',
                'regex:/^(https?:\/\/)?(www\.)?(youtube\.com|youtu\.be)\/.+$/'
            ],
            'num_clips' => 'nullable|integer|min:1|max:10',
            'watermark' => 'nullable|image|max:2048', // Max 2MB
        ]);

        // Rate limiting could be done via middleware 'throttle:5,60' in routes, implemented here manually if needed or via route definition.
        // Assuming route middleware preference for rate limiting.

        $numClips = $request->input('num_clips', 1);
        $watermarkPath = null;
        $watermarkUrl = null;

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
            'youtube_url' => $request->input('url'),
            'num_clips' => $numClips,
            'watermark_path' => $watermarkPath,
            'status' => 'processing'
        ]);

        // 2. "Tendang" Bola ke n8n
        try {
            $payload = [
                'youtube_url' => $request->input('url'),
                'num_clips' => (int) $numClips,
                'watermark_url' => $watermarkUrl,
                'task_id' => $task->id,
            ];

            Log::info('Sending payload to n8n:', $payload);

            // Updated Endpoint
            $response = Http::timeout(30)->asJson()->withHeaders([
                'x-api-key' => '123456' // Assuming auth is static or config based
            ])->post('https://n8n.dimascndraa.me/webhook/video-clipper', $payload);

            if ($response->failed()) {
                $task->update(['status' => 'failed']);
                Log::error('N8N Error:', ['status' => $response->status(), 'body' => $response->body()]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Gagal request ke n8n. Status: ' . $response->status(),
                    'debug' => $response->body()
                ], 500);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Video sedang diproses oleh AI',
                'task' => $task
            ]);
        } catch (\Exception $e) {
            $task->update(['status' => 'failed']);
            Log::error('Controller Error:', ['message' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Koneksi Gagal: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Handle callback from n8n.
     */
    public function handleCallback(Request $request)
    {
        // Validation
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
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
            'error' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            Log::warning('N8N Callback Validation Failed', $validator->errors()->toArray());
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 422);
        }

        $task = VideoTask::find($request->task_id);

        if (!$task) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        DB::beginTransaction();
        try {
            if ($request->status === 'failed') {
                $task->update([
                    'status' => 'failed',
                    // Assuming we might want to store error reason in ai_summary or a new field, using ai_summary for now
                    'ai_summary' => $request->error ?? 'Unknown error from N8N'
                ]);
            } else {
                // Update Task Detail
                $task->update([
                    'status' => 'completed',
                    'total_clips' => $request->total_clips,
                    'ai_summary' => 'Processed ' . $request->total_clips . ' clips.',
                ]);

                // Insert Clips
                foreach ($request->clips as $clipData) {
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
