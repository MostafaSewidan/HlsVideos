@push('hls-styles')
    @include('hls-videos::components._cssvideo')
@endpush
@php
    $videoType = isset($videoType) ? $videoType : 'hls';
@endphp
<div class="video-container">
    {{-- <div class="video-overlay-left" onclick="alert('here')">
        <i class="fa fa-chevron-left"></i>
        <i class="fa fa-chevron-left"></i>
        <i class="fa fa-chevron-left"></i>
        <i class="fa fa-chevron-left"></i>
        <i class="fa fa-chevron-left"></i>
    </div>
    <div class="video-overlay-right" onclick="alert('here')"></div> --}}
    <video id="player" playsinline controls poster="{{ $video->thumb_url }}" class="plyr">
        @if ($videoType === 'hls')
            <source src="{{ route(config('hls-videos.access_route_stream'), [$video->id]) }}"
                type="application/x-mpegURL" />
        @else
            <source src="{{ $video->original_video_link }}" type="video/mp4" />
        @endif
    </video>
    <div class="video-loading-overlay" id="video-loader">
        <div class="spinner"></div>
    </div>
</div>
@push('hls-scripts')
    @include('hls-videos::components._jsvideo', ['videoType' => $videoType])
@endpush
