@push('hls-styles')
    @include('hls-videos::components._cssvideo')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <style>
        .video-overlay-left-icon {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 2px;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 10px;
            padding: 10px;
            cursor: pointer;
            transition: transform 0.3s ease-out, opacity 0.3s ease-out;
        }

        .video-overlay-left-icon.slide-left {
            animation: slideLeftEffect 2.5s ease-out forwards;
        }

        @keyframes slideLeftEffect {
            0% {
                transform: translateX(0);
                opacity: 1;
            }

            50% {
                transform: translateX(-100%);
                opacity: 0.5;
            }

            100% {
                transform: translateX(-150%);
                opacity: 0;
            }
        }
    </style>
@endpush
<div class="video-container">
    <div class="video-overlay-left">
        {{-- <div class="video-overlay-left-icon" id="video-overlay-left-icon">
            <i class="fa fa-chevron-left"></i>
            <i class="fa fa-chevron-left"></i>
            <i class="fa fa-chevron-left"></i>
            <i class="fa fa-chevron-left"></i>
            <i class="fa fa-chevron-left"></i>
        </div> --}}
    </div>
    <div class="video-overlay-right" id="video-overlay-right"></div>
    <video id="player" playsinline controls poster="{{ $video->thumb_url }}" class="plyr">
        <source src="{{ route(config('hls-videos.access_route_stream'), [$video->id]) }}" type="application/x-mpegURL" />
    </video>
    <div class="video-loading-overlay" id="video-loader">
        <div class="spinner"></div>
    </div>
</div>

@push('hls-scripts')
    @include('hls-videos::components._jsvideo')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Helper to detect single and double tap on mobile
            function addTapListeners(element, doubleTapCallback, singleTapCallback) {
                let lastTap = 0;
                let timeout;
                element.addEventListener('touchend', function(e) {
                    const currentTime = new Date().getTime();
                    const tapLength = currentTime - lastTap;
                    clearTimeout(timeout);
                    if (tapLength < 300 && tapLength > 0) {
                        doubleTapCallback(e);
                        lastTap = 0;
                    } else {
                        lastTap = currentTime;
                        timeout = setTimeout(() => {
                            singleTapCallback(e);
                        }, 350);
                    }
                });
            }

            // Desktop double-click and single click events
            const leftOverlay = document.querySelector('.video-overlay-left');
            const rightOverlay = document.querySelector('.video-overlay-right');

            leftOverlay.addEventListener('dblclick', function(e) {
                player.rewind(10);
            });
            rightOverlay.addEventListener('dblclick', function(e) {
                player.forward(10);
            });

            leftOverlay.addEventListener('click', function(e) {
                // Toggle between pause and play on single click
                if (player.playing) {
                    player.pause();
                } else {
                    player.play();
                }
            });
            rightOverlay.addEventListener('click', function(e) {
                if (player.playing) {
                    player.pause();
                } else {
                    player.play();
                }
            });

            // Mobile touch tap events
            addTapListeners(
                leftOverlay,
                function(e) {
                    player.rewind(10);
                },
                function(e) {
                    // Toggle between pause and play on single tap
                    if (player.playing) {
                        player.pause();
                    } else {
                        player.play();
                    }
                }
            );
            addTapListeners(
                rightOverlay,
                function(e) {
                    player.forward(10);
                },
                function(e) {
                    if (player.playing) {
                        player.pause();
                    } else {
                        player.play();
                    }
                }
            );
        });
    </script>
@endpush
