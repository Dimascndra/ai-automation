@extends('layouts.index')

@section('title', 'Video Task Details')

@section('subheader')
    @component('layouts.partials._subheader.subheader-v1')
        @slot('title')
            Task #{{ $task->id }}
        @endslot
        @slot('other')
            <span class="text-muted font-weight-bold mr-4">Details & Clips</span>
        @endslot
        @slot('action')
            <a href="{{ route('ai-clipper.index') }}" class="btn btn-light-primary font-weight-bold btn-sm">Back to List</a>
        @endslot
    @endcomponent
@endsection

@section('content')
    <div class="container">
        <div class="card card-custom gutter-b">
            <div class="card-header">
                <h3 class="card-title">Task Information</h3>
            </div>
            <div class="card-body">
                <div class="form-group row my-2">
                    <label class="col-4 col-form-label">YouTube URL:</label>
                    <div class="col-8">
                        <span class="form-control-plaintext font-weight-bolder">
                            <a href="{{ $task->youtube_url }}" target="_blank">{{ $task->youtube_url }}</a>
                        </span>
                    </div>
                </div>
                <div class="form-group row my-2">
                    <label class="col-4 col-form-label">Status:</label>
                    <div class="col-8">
                        <span
                            class="form-control-plaintext font-weight-bolder text-uppercase label label-inline label-light-{{ $task->status == 'completed' ? 'success' : ($task->status == 'failed' ? 'danger' : 'warning') }}">
                            {{ $task->status }}
                        </span>
                    </div>
                </div>
                <div class="form-group row my-2">
                    <label class="col-4 col-form-label">Total Clips:</label>
                    <div class="col-8">
                        <span class="form-control-plaintext font-weight-bolder">{{ $task->total_clips ?? 0 }}</span>
                    </div>
                </div>
                @if ($task->ai_summary)
                    <div class="form-group row my-2">
                        <label class="col-4 col-form-label">Summary / Error:</label>
                        <div class="col-8">
                            <p class="form-control-plaintext text-muted">{{ $task->ai_summary }}</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="card card-custom gutter-b">
            <div class="card-header">
                <h3 class="card-title">Generated Clips</h3>
            </div>
            <div class="card-body">
                <div class="timeline timeline-3">
                    <div class="timeline-items">
                        @forelse($task->clips as $clip)
                            <div class="timeline-item">
                                <div class="timeline-media">
                                    <i class="flaticon2-layers text-primary"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <div class="mr-2">
                                            <a href="#" class="text-dark-75 text-hover-primary font-weight-bold">
                                                Clip #{{ $clip->clip_number }}: {{ $clip->title }}
                                            </a>
                                            <span class="text-muted ml-2">{{ $clip->formatted_duration }}</span>
                                        </div>
                                        <div class="dropdown ml-2" data-toggle="tooltip" title=""
                                            data-placement="left" data-original-title="Actions">
                                            <a href="{{ $clip->video_clip_url }}" target="_blank"
                                                class="btn btn-hover-light-primary btn-sm btn-icon">
                                                <i class="flaticon2-download"></i>
                                            </a>
                                        </div>
                                    </div>
                                    <p class="p-0">
                                        {{ $clip->reason }}
                                    </p>
                                </div>
                            </div>
                        @empty
                            <div class="text-center text-muted">No clips available.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
