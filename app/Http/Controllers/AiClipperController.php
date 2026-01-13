<?php

namespace App\Http\Controllers;

use App\Models\VideoTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AiClipperController extends Controller
{
    /**
     * Display the listing of the resource.
     */
    public function index(Request $request)
    {
        $tasks = VideoTask::orderBy('created_at', 'desc')->get();

        if ($request->ajax()) {
            $tasks = $tasks->map(function ($task) {
                $task->time_ago = $task->created_at->diffForHumans();
                return $task;
            });
            return response()->json($tasks);
        }

        return view('pages.ai-clipper.index', compact('tasks'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'url' => 'required|url',
        ]);

        // 1. Simpan Data Awal (Status: Pending)
        $task = VideoTask::create([
            'youtube_url' => $request->url,
            'status' => 'processing'
        ]);

        // 2. "Tendang" Bola ke n8n
        try {
            Http::withHeaders([
                'x-api-key' => '123456'
            ])->post('https://n8n.dimascndraa.me/webhook/autoclip', [
                'url' => $request->url,
                'task_id' => $task->id,
            ]);

            return response()->json(['status' => 'success', 'message' => 'AI sedang bekerja...', 'task' => $task]);
        } catch (\Exception $e) {
            $task->update(['status' => 'failed']);
            return response()->json(['status' => 'error', 'message' => 'Gagal konek ke n8n'], 500);
        }
    }

    /**
     * Handle callback from n8n.
     */
    public function handleCallback(Request $request)
    {
        // 1. Cari Tiket berdasarkan ID yang dikirim n8n
        $task = VideoTask::find($request->task_id);

        if (!$task) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        // 2. Update Status jadi Completed & Simpan Nama File
        $task->update([
            'status' => 'completed',
            'output_filename' => $request->filename,
            'ai_summary' => $request->reason
        ]);

        return response()->json(['message' => 'Laporan diterima!']);
    }
}
