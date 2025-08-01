<div>
    @switch($video?->status)
        @case(HlsVideos\Models\HlsVideo::READY)
            <a href="javascript:;" onclick="deleteVideo('{{ route('hls.videos.delete', $video->id) }}')" class="btn btn-danger btn-xs">
                <i class="fa fa-trash"></i>
            </a>
            <div class="col-md-12">
                <video id="player" playsinline controls poster="{{ $video->thumb_url }}">
                    <!-- Optional fallback -->
                    <source src="{{ route(config('hls-videos.access_route_stream'), [$video->id]) }}"
                        type="application/x-mpegURL">
                </video>
            </div>
        @break

        @case(HlsVideos\Models\HlsVideo::UPLOADED)
        @case(HlsVideos\Models\HlsVideo::PROCESSING)
            <div class="col-md-12">
                <center>
                    <div style="position: relative; display: inline-block; width: 100%;">
                        <img src="{{ $video->thumb_url }}" alt="" style="width:100%; display: block;">
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 80%; margin: 0;">
                             <span class="loader2"></span>
                        </div>
                    </div>
                </center>
            </div>
        @break

        @default
            <div id="drag-drop-area"></div>
        @break
    @endswitch
</div>
