@extends('layouts.index')

@section('title', 'AI Video Clipper')

@section('subheader')
    @component('layouts.partials._subheader.subheader-v1')
        @slot('title')
            AI Video Clipper (v1.0)
        @endslot
        @slot('other')
            <span class="text-muted font-weight-bold mr-4">Otak & Urat Saraf System</span>
        @endslot
        @slot('action')
        @endslot
    @endcomponent
@endsection

@section('content')
    <div class="container">
        <div class="card card-custom gutter-b">
            <div class="card-header">
                <h3 class="card-title">Input YouTube URL</h3>
            </div>
            <div class="card-body">
                <form id="clipperForm">
                    @csrf
                    <div class="form-group">
                        <label>YouTube URL</label>
                        <input type="url" name="url" class="form-control"
                            placeholder="https://youtube.com/watch?v=..." required>
                    </div>
                    <div class="form-group">
                        <label>Number of Clips (Optional)</label>
                        <input type="number" name="num_clips" class="form-control" placeholder="1" value="1"
                            min="1" max="10">
                        <small class="form-text text-muted">How many clips do you want generated from this video?</small>
                    </div>
                    <button type="submit" class="btn btn-primary" id="btnSubmit">
                        <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"
                            id="btnSpinner"></span>
                        Process Video (Asynchronous)
                    </button>
                </form>
                <div id="statusMessage" class="mt-3"></div>
            </div>
        </div>

        <!-- Task List -->
        <div class="card card-custom">
            <div class="card-header">
                <h3 class="card-title">Recent Tasks</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>URL</th>
                                <th>Status (Auto Refresh)</th>
                                <th>Result</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tasks as $task)
                                <tr>
                                    <td>{{ $task->id }}</td>
                                    <td
                                        style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <a href="{{ $task->youtube_url }}" target="_blank">{{ $task->youtube_url }}</a>
                                    </td>
                                    <td>
                                        @if ($task->status == 'completed')
                                            <span class="badge badge-success">Completed</span>
                                        @elseif($task->status == 'processing')
                                            <span class="badge badge-warning">Processing</span>
                                            <div class="spinner-border spinner-border-sm text-warning ml-2" role="status">
                                            </div>
                                        @elseif($task->status == 'failed')
                                            <span class="badge badge-danger">Failed</span>
                                        @else
                                            <span class="badge badge-secondary">{{ $task->status }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($task->status == 'completed')
                                            <div class="d-flex flex-column">
                                                <small class="text-muted mb-1">AI Reason:
                                                    {{ Str::limit($task->ai_summary, 50) }}</small>
                                                <div>
                                                    <a href="{{ asset('videos/' . $task->output_filename) }}"
                                                        class="btn btn-sm btn-light-primary font-weight-bold mr-2"
                                                        target="_blank">View</a>
                                                    <a href="{{ asset('videos/' . $task->output_filename) }}"
                                                        class="btn btn-sm btn-primary font-weight-bold"
                                                        download>Download</a>
                                                </div>
                                            </div>
                                        @elseif($task->status == 'processing')
                                            <span class="text-muted">Waiting for callback...</span>
                                        @elseif($task->status == 'failed')
                                            <span class="text-danger">Error occurred</span>
                                        @endif
                                    </td>
                                    <td>{{ $task->created_at->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        // Handle Form Submit
        $('#clipperForm').on('submit', function(e) {
            e.preventDefault();
            let btn = $('#btnSubmit');
            let spinner = $('#btnSpinner');

            btn.prop('disabled', true);
            spinner.removeClass('d-none');

            $.ajax({
                url: "{{ route('ai-clipper.store') }}",
                method: "POST",
                data: $(this).serialize(),
                success: function(response) {
                    $('#statusMessage').html('<div class="alert alert-success">' + response.message +
                        '</div>');
                    $('#clipperForm')[0].reset();
                    btn.prop('disabled', false);
                    spinner.addClass('d-none');
                    fetchTasks(); // Refresh list immediately
                },
                error: function(xhr) {
                    $('#statusMessage').html('<div class="alert alert-danger">Error: ' + xhr
                        .responseJSON.message + '</div>');
                    btn.prop('disabled', false);
                    spinner.addClass('d-none');
                }
            });
        });

        // AJAX Polling for Status Updates
        function fetchTasks() {
            $.ajax({
                url: "{{ route('ai-clipper.index') }}",
                method: "GET",
                success: function(tasks) {
                    let rows = '';
                    tasks.forEach(task => {
                        let statusBadge = '';
                        let resultHtml = '';

                        // Status Logic
                        if (task.status === 'completed') {
                            statusBadge = '<span class="badge badge-success">Completed</span>';
                            resultHtml = `
                            <div class="d-flex flex-column">
                                <small class="text-muted mb-1">AI Reason: ${task.ai_summary ? task.ai_summary.substring(0, 50) : ''}...</small>
                                <div>
                                    <a href="/videos/${task.output_filename}" class="btn btn-sm btn-light-primary font-weight-bold mr-2" target="_blank">View</a>
                                    <a href="/videos/${task.output_filename}" class="btn btn-sm btn-primary font-weight-bold" download>Download</a>
                                </div>
                            </div>`;
                        } else if (task.status === 'processing') {
                            statusBadge =
                                '<span class="badge badge-warning">Processing</span><div class="spinner-border spinner-border-sm text-warning ml-2" role="status"></div>';
                            resultHtml = '<span class="text-muted">Waiting for callback...</span>';
                        } else if (task.status === 'failed') {
                            statusBadge = '<span class="badge badge-danger">Failed</span>';
                            resultHtml = '<span class="text-danger">Error occurred</span>';
                        } else {
                            statusBadge = `<span class="badge badge-secondary">${task.status}</span>`;
                            resultHtml = '-';
                        }

                        rows += `
                        <tr>
                            <td>${task.id}</td>
                            <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <a href="${task.youtube_url}" target="_blank">${task.youtube_url}</a>
                            </td>
                            <td>${statusBadge}</td>
                            <td>${resultHtml}</td>
                            <td>${task.time_ago}</td>
                        </tr>
                    `;
                        // Note: Date formatting in JS is verbose, keeping raw string for now or use library like moment if available
                    });
                    $('tbody').html(rows);
                }
            });
        }

        // Poll every 3 seconds
        setInterval(fetchTasks, 3000);

        // Initial load handled by server-side render, but we can fetch immediately to ensure sync if needed,
        // or just let the interval take over.
    </script>
@endsection
