@push('hls-styles')
    @include('hls-videos::components._cssvideo')
@endpush
<div class="video-container">
    {{-- <div class="video-overlay-left" onclick="alert('here')">
        <i class="fa fa-chevron-left"></i>
        <i class="fa fa-chevron-left"></i>
        <i class="fa fa-chevron-left"></i>
        <i class="fa fa-chevron-left"></i>
        <i class="fa fa-chevron-left"></i>
    </div>
    <div class="video-overlay-right" onclick="alert('here')"></div> --}}
    <video id="player" playsinline controls poster="{{ $video->thumb_url }}">
        <source src="{{ route(config('hls-videos.access_route_stream'), [$video->id]) }}" type="application/x-mpegURL" />
    </video>
    <div class="video-loading-overlay" id="video-loader">
        <div class="spinner"></div>
    </div>
</div>

@push('hls-scripts')
    @include('hls-videos::components._jsvideo')
@endpush
