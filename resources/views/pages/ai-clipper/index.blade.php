@extends('layouts.index')

@section('title', 'AI Video Clipper')

@section('subheader')
    @component('layouts.partials._subheader.subheader-v1')
        @slot('title')
            AI Video Clipper
        @endslot
        @slot('other')
            <span class="text-muted font-weight-bold mr-4">Create viral short clips from YouTube videos</span>
        @endslot
        @slot('action')
        @endslot
    @endcomponent
@endsection

@section('content')
    <div class="container">
        <div class="row">
            <!-- Left Side: Input Form -->
            <div class="col-lg-4">
                <div class="card card-custom gutter-b">
                    <div class="card-header border-0 pt-5">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label font-weight-bolder text-dark">New Project</span>
                            <span class="text-muted mt-3 font-weight-bold font-size-sm">Submit video for processing</span>
                        </h3>
                    </div>
                    <div class="card-body pt-2">
                        <form id="clipperForm">
                            @csrf
                            <div class="form-group pb-2">
                                <label class="font-weight-bolder text-dark">YouTube URL</label>
                                <input type="url" name="url" class="form-control form-control-solid form-control-lg"
                                    placeholder="https://youtube.com/watch?v=..." required />
                                <span class="form-text text-muted">Paste the full YouTube video URL.</span>
                            </div>

                            <div class="form-group pb-2">
                                <label class="font-weight-bolder text-dark">Clips Quantity</label>
                                <div class="input-group">
                                    <input type="number" name="num_clips"
                                        class="form-control form-control-solid form-control-lg" placeholder="1"
                                        value="5" min="1" max="10" />
                                    <div class="input-group-append">
                                        <span class="input-group-text">Clips</span>
                                    </div>
                                </div>
                                <span class="form-text text-muted">Target number of clips (1-10).</span>
                            </div>

                            <div class="form-group pb-2">
                                <label class="font-weight-bolder text-dark">Watermark (Optional)</label>
                                <div class="custom-file">
                                    <input type="file" class="custom-file-input" name="watermark" id="watermarkFile"
                                        accept="image/*">
                                    <label class="custom-file-label" for="watermarkFile">Choose file</label>
                                </div>
                                <span class="form-text text-muted">Transparent PNG recommended (Max 2MB).</span>
                            </div>

                            <div id="statusMessage" class="mb-4"></div>

                            <button type="submit" class="btn btn-primary btn-lg btn-block font-weight-bold" id="btnSubmit">
                                <span class="spinner-border spinner-border-sm d-none mr-2" role="status" aria-hidden="true"
                                    id="btnSpinner"></span>
                                Start Processing
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right Side: Task List -->
            <div class="col-lg-8">
                <div class="card card-custom gutter-b">
                    <div class="card-header border-0 pt-5">
                        <h3 class="card-title align-items-start flex-column">
                            <span class="card-label font-weight-bolder text-dark">Recent Tasks</span>
                            <span class="text-muted mt-3 font-weight-bold font-size-sm">Real-time processing status</span>
                        </h3>
                        <div class="card-toolbar">
                            <a href="#" onclick="fetchTasks()" class="btn btn-icon btn-sm btn-light-primary mr-1"
                                data-toggle="tooltip" title="Refresh List">
                                <i class="ki ki-refresh icon-nm"></i>
                            </a>
                        </div>
                    </div>
                    <div class="card-body pt-2">
                        <div class="table-responsive">
                            <table class="table table-borderless table-vertical-center">
                                <thead>
                                    <tr>
                                        <th class="p-0" style="width: 50px"></th>
                                        <th class="p-0" style="min-width: 200px"></th>
                                        <th class="p-0" style="min-width: 120px"></th>
                                        <th class="p-0" style="min-width: 80px"></th>
                                    </tr>
                                </thead>
                                <tbody id="tasksTableBody">
                                    <!-- Populated by JS -->
                                    @foreach ($tasks as $task)
                                        @include('pages.ai-clipper.partials.task_row', ['task' => $task])
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Clips Modal -->
    <div class="modal fade" id="clipsModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title font-weight-bolder">Generated Clips</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <i aria-hidden="true" class="ki ki-close"></i>
                    </button>
                </div>
                <div class="modal-body" id="clipsModalBody">
                    <div class="d-flex justify-content-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="sr-only">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light-primary font-weight-bold"
                        data-dismiss="modal">Close</button>
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
            let formData = new FormData(this);

            btn.prop('disabled', true);
            spinner.removeClass('d-none');
            $('#statusMessage').html('');

            $.ajax({
                url: "{{ route('ai-clipper.store') }}",
                method: "POST",
                data: formData,
                contentType: false,
                processData: false,
                success: function(response) {
                    $('#statusMessage').html(
                        `<div class="alert alert-custom alert-light-success fade show mb-5" role="alert">
                            <div class="alert-icon"><i class="flaticon2-check-mark"></i></div>
                            <div class="alert-text">${response.message}</div>
                        </div>`
                    );
                    $('#clipperForm')[0].reset();
                    $('.custom-file-label').html('Choose file');
                    btn.prop('disabled', false);
                    spinner.addClass('d-none');
                    fetchTasks();
                },
                error: function(xhr) {
                    let errorMsg = xhr.responseJSON?.message || 'Error occurred';
                    $('#statusMessage').html(
                        `<div class="alert alert-custom alert-light-danger fade show mb-5" role="alert">
                            <div class="alert-icon"><i class="flaticon-warning"></i></div>
                            <div class="alert-text">${errorMsg}</div>
                        </div>`
                    );
                    btn.prop('disabled', false);
                    spinner.addClass('d-none');
                }
            });
        });

        $('.custom-file-input').on('change', function() {
            let fileName = $(this).val().split('\\').pop();
            $(this).next('.custom-file-label').addClass("selected").html(fileName);
        });

        // Open Clips Modal
        function viewClips(taskId) {
            $('#clipsModal').modal('show');
            $('#clipsModalBody').html(`
                <div class="d-flex justify-content-center py-5">
                    <div class="spinner-border text-primary" role="status"><span class="sr-only">Loading...</span></div>
                </div>
            `);

            $.ajax({
                url: `/ai-clipper/${taskId}`,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }, // Force JSON response if controller checks AJAX
                success: function(data) {
                    if (data.clips && data.clips.length > 0) {
                        let html = '<div class="list list-timeline list-timeline-skin-light">';
                        data.clips.forEach(clip => {
                            html += `
                                <div class="list-timeline-item">
                                    <span class="list-timeline-badge list-timeline-badge-success"></span>
                                    <span class="list-timeline-text">
                                        <span class="font-weight-bold text-dark d-block">Clip ${clip.clip_number}: ${clip.title}</span>
                                        <span class="text-muted font-size-sm">${clip.reason || 'No summary'}</span>
                                    </span>
                                    <span class="list-timeline-time text-right" style="width: 150px">
                                        <span class="badge label label-light-primary label-inline font-weight-bold mr-2">${clip.formatted_duration || formatDuration(clip.duration)}</span>
                                        <a href="${clip.video_clip_url}" target="_blank" class="btn btn-icon btn-xs btn-light-primary" title="Download">
                                            <i class="flaticon2-download"></i>
                                        </a>
                                    </span>
                                </div>
                            `;
                        });
                        html += '</div>';
                        $('#clipsModalBody').html(html);
                    } else {
                        $('#clipsModalBody').html(
                            '<div class="text-center p-4 text-muted">No clips found for this task.</div>');
                    }
                },
                error: function() {
                    $('#clipsModalBody').html('<div class="alert alert-danger">Failed to load clips.</div>');
                }
            });
        }

        function formatDuration(seconds) {
            if (!seconds) return "00:00";
            let m = Math.floor(seconds / 60);
            let s = Math.floor(seconds % 60);
            return `${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}`;
        }

        function fetchTasks() {
            $.ajax({
                url: "{{ route('ai-clipper.index') }}",
                success: function(tasks) {
                    let html = '';
                    tasks.forEach(task => {
                        html += renderTaskRow(task);
                    });
                    $('#tasksTableBody').html(html);
                }
            });
        }

        function renderTaskRow(task) {
            let statusLabel = '';
            let actionBtn = '';

            if (task.status === 'completed') {
                statusLabel =
                    '<span class="label label-lg label-light-success label-inline font-weight-bold">Completed</span>';
                actionBtn = `
                    <button onclick="viewClips(${task.id})" class="btn btn-sm btn-primary font-weight-bolder">
                        <i class="flaticon-layers mr-1"></i> View Clips (${task.total_clips || task.clips?.length || 0})
                    </button>`;
            } else if (task.status === 'processing') {
                statusLabel =
                    '<span class="label label-lg label-light-warning label-inline font-weight-bold">Processing</span>';
                actionBtn = `
                    <div class="d-flex align-items-center">
                        <div class="spinner-grow spinner-grow-sm text-warning mr-2" role="status"></div>
                        <span class="text-muted font-size-sm">AI Working...</span>
                    </div>`;
            } else if (task.status === 'failed') {
                statusLabel = '<span class="label label-lg label-light-danger label-inline font-weight-bold">Failed</span>';
                actionBtn = '<span class="text-danger font-size-sm">Error Occurred</span>';
            } else {
                statusLabel =
                    `<span class="label label-lg label-light-dark label-inline font-weight-bold">${task.status}</span>`;
            }

            return `
                <tr>
                    <td class="pl-0 py-5">
                        <div class="symbol symbol-50 symbol-light mr-2">
                            <span class="symbol-label">
                                <span class="svg-icon svg-icon-xl svg-icon-primary">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24px" height="24px" viewBox="0 0 24 24" version="1.1">
                                        <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                                            <rect x="0" y="0" width="24" height="24"/>
                                            <path d="M9.82866499,18.2771971 L16.5693679,12.3976203 C16.7774696,12.2161036 16.7990211,11.9002555 16.6175044,11.6921539 C16.6029128,11.6754252 16.5872233,11.6596867 16.5704537,11.6450431 L9.82975082,5.75954946 C9.62174935,5.57793524 9.30612458,5.59954389 9.12451036,5.80754536 C9.0560866,5.88590687 9.01844222,5.98705001 9.01844222,6.09115714 L9.01844222,17.9455938 C9.01844222,18.2217362 9.24230002,18.4455938 9.51844222,18.4455938 C9.62264669,18.4455938 9.72389182,18.4078426 9.80231998,18.3393043 Z" fill="#000000"/>
                                        </g>
                                    </svg>
                                </span>
                            </span>
                        </div>
                    </td>
                    <td class="pl-0">
                        <a href="${task.youtube_url}" target="_blank" class="text-dark-75 font-weight-bolder text-hover-primary mb-1 font-size-lg text-truncate" style="max-width: 250px; display:block;">
                            ${task.youtube_url}
                        </a>
                        <span class="text-muted font-weight-bold d-block">${task.time_ago}</span>
                    </td>
                    <td class="text-right">
                        ${statusLabel}
                    </td>
                    <td class="text-right pr-0">
                        ${actionBtn}
                    </td>
                </tr>
            `;
        }

        setInterval(fetchTasks, 5000);
    </script>
@endsection
