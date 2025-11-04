<div>
    @switch($video?->status)
        @case(HlsVideos\Models\HlsVideo::READY)
            <a href="javascript:;" onclick="deleteVideo('{{ route('hls.videos.delete', $video->id) }}')"
                class="btn btn-danger btn-xs">
                <i class="fa fa-trash"></i>
            </a>
            @if (config('hls-videos.video_player_optionstatus'))
                <div class="col-md-12">
                    <div class="video-container">
                        <video id="player" playsinline controls poster="{{ $video->thumb_url }}">
                            <source src="{{ route(config('hls-videos.access_route_stream'), [$video->id]) }}"
                                type="application/x-mpegURL" />
                        </video>
                        <div class="video-loading-overlay" id="video-loader">
                            <div class="spinner"></div>
                        </div>
                    </div>
                </div>
            @endif
        @break

        @case(HlsVideos\Models\HlsVideo::UPLOADED)
        @case(HlsVideos\Models\HlsVideo::PROCESSING)
            <div class="col-md-12">
                <center>
                    <div style="position: relative; display: inline-block; width: 100%;">
                        <img src="{{ $video->thumb_url }}" alt="" style="width:100%; display: block;">
                        <div
                            style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 80%; margin: 0;">
                            <span class="loader2"></span>
                        </div>
                    </div>
                </center>
            </div>
        @break

        @default
            <div id="drag-drop-area">
                <!-- Existing Videos Panel -->
                <div id="existing-videos-panel" class="existing-videos-panel">
                    <div id="existing-breadcrumb" style="padding: 12px 20px; border-bottom: 1px solid #e9ecef; display: none;">
                        <!-- Breadcrumb will be inserted here -->
                    </div>

                    <div class="existing-videos-search">
                        <input type="text" id="existing-videos-search" placeholder="بحث في الفيديوهات...">
                    </div>

                    <div class="existing-videos-content">
                        <div id="existing-videos-content">
                            <div class="existing-videos-loading">
                                <i class="fas fa-spinner fa-spin"></i>
                            </div>
                        </div>
                        <div class="hls-load-more-btn" id="hls-load-more-button">
                            <i class="fas fa-arrow-down"></i>
                            تحميل المزيد
                        </div>
                    </div>

                    <div class="existing-videos-footer">
                        <button class="btn btn-secondary" id="cancel-select-video">
                            إلغاء
                        </button>
                        <button class="btn-select-video" id="confirm-select-video" disabled>
                            <i class="fas fa-check mx-2"></i>
                            استخدم هذا الفيديو
                        </button>
                    </div>
                </div>
            </div>
        @break

    @endswitch
</div>
