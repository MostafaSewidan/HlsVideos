{{--
    Student player on Video.js 10 (hls-videos.player = videojs).
    Same inputs as the Plyr view (components/video.blade.php): $video, $videoType,
    $fullScreenStatus, $password, $authToken, $version.
--}}
@push('hls-styles')
    @include('hls-videos::components.videojs._css')
@endpush

<div class="hls-vjs-container" dir="ltr" data-fullscreen="{{ $fullScreenStatus === 'off' ? 'off' : 'on' }}">
    <media-i18n lang="{{ app()->getLocale() }}">
        <video-player id="hls-vjs-player" poster="{{ $video->thumb_url }}">
            <video-skin class="hls-vjs-skin">
                @if ($videoType === 'hls')
                    <hlsjs-video id="player" playsinline preload="metadata"
                        data-src="{{ route(config('hls-videos.access_route_stream'), [$video->id]) }}"></hlsjs-video>
                @else
                    <video id="player" playsinline preload="metadata"
                        data-src="{{ $video->original_video_link }}"></video>
                @endif
            </video-skin>
        </video-player>
    </media-i18n>
</div>

@push('hls-scripts')
    @include('hls-videos::components.videojs._js')
@endpush
