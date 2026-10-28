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
            <{{ $videojsSkin['tag'] }} class="hls-vjs-skin">
                @if ($videoType === 'hls')
                    {{-- No picture-in-picture, no casting (Chromecast / AirPlay). --}}
                    <hlsjs-video id="player" playsinline preload="metadata"
                        disablepictureinpicture disableremoteplayback x-webkit-airplay="deny"
                        data-src="{{ route(config('hls-videos.access_route_stream'), [$video->id]) }}"></hlsjs-video>
                @else
                    <video id="player" playsinline preload="metadata"
                        disablepictureinpicture disableremoteplayback x-webkit-airplay="deny"
                        data-src="{{ $video->original_video_link }}"></video>
                @endif
            </{{ $videojsSkin['tag'] }}>
        </video-player>
    </media-i18n>
</div>

@push('hls-scripts')
    @include('hls-videos::components.videojs._js')
@endpush
