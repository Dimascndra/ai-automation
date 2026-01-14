@php
    $statusLabel = '';
    $actionBtn = '';

    if ($task->status === 'completed') {
        $statusLabel =
            '<span class="label label-lg label-light-success label-inline font-weight-bold">Completed</span>';
        $btnText = 'View Clips (' . ($task->total_clips ?? ($task->clips->count() ?? 0)) . ')';
        $actionBtn =
            '
            <button onclick="viewClips(' .
            $task->id .
            ')" class="btn btn-sm btn-primary font-weight-bolder">
                <i class="flaticon-layers mr-1"></i> ' .
            $btnText .
            '
            </button>';
    } elseif ($task->status === 'processing') {
        $statusLabel =
            '<span class="label label-lg label-light-warning label-inline font-weight-bold">Processing</span>';
        $actionBtn = '
            <div class="d-flex align-items-center">
                <div class="spinner-grow spinner-grow-sm text-warning mr-2" role="status"></div>
                <span class="text-muted font-size-sm">AI Working...</span>
            </div>';
    } elseif ($task->status === 'failed') {
        $statusLabel = '<span class="label label-lg label-light-danger label-inline font-weight-bold">Failed</span>';
        $actionBtn = '<span class="text-danger font-size-sm">Error Occurred</span>';
    } else {
        $statusLabel =
            '<span class="label label-lg label-light-dark label-inline font-weight-bold">' . $task->status . '</span>';
    }
@endphp

<tr>
    <td class="pl-0 py-5">
        <div class="symbol symbol-50 symbol-light mr-2">
            <span class="symbol-label">
                <span class="svg-icon svg-icon-xl svg-icon-primary">
                    <!-- Standard Play Icon -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="24px" height="24px" viewBox="0 0 24 24" version="1.1">
                        <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                            <rect x="0" y="0" width="24" height="24" />
                            <path
                                d="M9.82866499,18.2771971 L16.5693679,12.3976203 C16.7774696,12.2161036 16.7990211,11.9002555 16.6175044,11.6921539 C16.6029128,11.6754252 16.5872233,11.6596867 16.5704537,11.6450431 L9.82975082,5.75954946 C9.62174935,5.57793524 9.30612458,5.59954389 9.12451036,5.80754536 C9.0560866,5.88590687 9.01844222,5.98705001 9.01844222,6.09115714 L9.01844222,17.9455938 C9.01844222,18.2217362 9.24230002,18.4455938 9.51844222,18.4455938 C9.62264669,18.4455938 9.72389182,18.4078426 9.80231998,18.3393043 Z"
                                fill="#000000" />
                        </g>
                    </svg>
                </span>
            </span>
        </div>
    </td>
    <td class="pl-0">
        <a href="{{ $task->youtube_url }}" target="_blank"
            class="text-dark-75 font-weight-bolder text-hover-primary mb-1 font-size-lg text-truncate"
            style="max-width: 250px; display:block;">
            {{ $task->youtube_url }}
        </a>
        <span class="text-muted font-weight-bold d-block">{{ $task->created_at->diffForHumans() }}</span>
    </td>
    <td class="text-right">
        {!! $statusLabel !!}
    </td>
    <td class="text-right pr-0">
        {!! $actionBtn !!}
    </td>
</tr>
