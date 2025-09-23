@push('hls-styles')
    <link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css" />
    <style>
        :root {
            --color-primary: #1f4f9a;
            --color-secondary: #23509f;
            --color-secondery: #23509f;
            --color-accent: #3bc9db;
            --color-dark-blue: #29425f;

            --video-bg: #000;
            --video-player-main: var(--color-primary);
            --video-overlay-bg: rgba(0, 0, 0, 0.6);
            --video-shadow-medium: rgba(0, 0, 0, 0.4);
            --video-shadow-strong: rgba(0, 0, 0, 0.6);
        }

        .video-container {
            position: relative;
            width: 100%;
            height: 100%;
            min-height: 200px;
            max-height: 100vh;
            background-color: var(--video-bg);
            overflow: hidden;
            box-shadow: 0 8px 32px var(--video-shadow-medium);
            transition: box-shadow 0.3s ease, transform 0.3s ease;
            aspect-ratio: 16 / 9;

            --plyr-color-main: var(--video-player-main);
            --plyr-video-background: var(--video-bg);
        }

        /* Ensure video container fits within iframe dimensions */
        @media (max-aspect-ratio: 16/9) {
            .video-container {
                width: 100%;
                height: auto;
                aspect-ratio: 16 / 9;
            }
        }

        @media (min-aspect-ratio: 16/9) {
            .video-container {
                width: auto;
                height: 100%;
                aspect-ratio: 16 / 9;
            }
        }

        .plyr__controls .plyr__controls__item:first-child {
            margin-left: auto !important;
            margin-right: 0 !important;
        }

        .plyr__controls button.plyr__controls__item {
            position: absolute !important;
            left: 2px;
        }

        .plyr__controls .plyr__controls__item:last-child {
            position: relative !important;
        }

        .plyr__progress__container {
            position: absolute;
            width: 98%;
            top: 10px;
        }

        .video-container video {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: contain !important;
            display: block;
        }

        .video-loading-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: var(--video-overlay-bg);
            z-index: 10;
        }

        .video-loading-overlay .spinner {
            width: 44px;
            height: 44px;
            border: 3px solid rgba(255, 255, 255, 0.25);
            border-top-color: var(--color-primary);
            border-radius: 50%;
            animation: cd-spin 1s linear infinite;
        }

        @keyframes cd-spin {
            to {
                transform: rotate(360deg);
            }
        }

        .plyr {
            height: 100%;
        }

        .plyr__controls {
            padding-inline: 10px;
        }

        .plyr__control--overlaid {
            position: absolute !important;
            top: 50% !important;
            left: 50% !important;
            transform: translate(-50%, -50%) !important;
            margin: 0 !important;
            background: var(--color-primary) !important;
            color: #fff !important;
            border-radius: 50% !important;
        }

        .plyr__controls__item.plyr__progress__container {
            position: absolute;
            top: 16px;
            width: 98%;
        }

        .plyr__controls .plyr__control[data-plyr="rewind"] {
            position: absolute !important;
            left: 10px !important;
        }

        .plyr__controls .plyr__control[data-plyr="play"] {
            position: absolute !important;
            left: 50px !important;
        }

        .plyr__controls .plyr__control[data-plyr="fast-forward"] {
            position: absolute !important;
            left: 90px !important;
        }

        .plyr__controls .plyr__time--current {
            position: absolute !important;
            left: 135px !important;
            bottom: 12px !important;

        }

        .plyr__controls .plyr__time--duration {
            position: absolute !important;
            left: 178px !important;
            bottom: 12px !important;

        }

        @media (max-width: 600px) {
            .plyr__controls__item.plyr__time--current.plyr__time {
                bottom: 9px !important;

            }

            .plyr__controls__item.plyr__progress__container {

                top: 1px !important;
            }

            .plyr__controls .plyr__control[data-plyr="play"] {
                left: 33px !important;
            }

            .plyr__controls .plyr__control[data-plyr="fast-forward"] {
                left: 55px !important;
            }

            .plyr__controls .plyr__time--current {
                left: 85px !important;
            }

            .plyr__controls .plyr__time--duration {
                left: 130px !important;
            }
        }
    </style>
@endpush
<div class="video-container">
    <video id="player" playsinline controls poster="{{ $video->thumb_url }}">
        <source src="{{ route(config('hls-videos.access_route_stream'), [$video->id]) }}"
            type="application/x-mpegURL" />
    </video>
    <div class="video-loading-overlay" id="video-loader">
        <div class="spinner"></div>
    </div>
</div>

@push('hls-scripts')
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>

    <script>
        function videoPlayerIoRun(source = null) {
            const video = document.getElementById("player");

            if (Hls.isSupported()) {
                const hls = new Hls();
                hls.loadSource(source ?? video.querySelector("source").src);
                hls.attachMedia(video);

                hls.on(Hls.Events.MANIFEST_PARSED, function() {


                    const availableQualities = hls.levels
                        .map(l => l.height)
                        .filter((v, i, a) => a.indexOf(v) === i)
                        .sort((a, b) => b - a);

                    initPlyr(availableQualities, hls);
                });
                // safari support 
            } else if (video.canPlayType("application/vnd.apple.mpegurl")) {
                video.addEventListener("loadedmetadata", () => {
                    initPlyr([], null);
                });
            }

            // const player = new Plyr(video);
        }

        function initPlyr(availableQualities, hls) {
            const video = document.getElementById("player");
            const loader = document.getElementById("video-loader");
            const i18n_ar = {
                restart: "إعادة التشغيل",
                rewind: "رجوع 10 ثواني",
                play: "تشغيل",
                pause: "إيقاف مؤقت",
                fastForward: "تقديم 10 ثواني",
                seek: "تخطي",
                seekLabel: "{seektime} ثانية",
                played: "تم التشغيل",
                buffered: "تم التحميل المؤقت",
                currentTime: "الوقت الحالي",
                duration: "المدة",
                volume: "الصوت",
                mute: "كتم الصوت",
                unmute: "إلغاء الكتم",
                enableCaptions: "تشغيل الترجمة",
                disableCaptions: "إيقاف الترجمة",
                download: "تحميل",
                enterFullscreen: "ملء الشاشة",
                exitFullscreen: "الخروج من ملء الشاشة",
                frameTitle: "مشغل للفيديو",
                captions: "الترجمة",
                settings: "الإعدادات",
                menuBack: "رجوع",
                speed: "السرعة",
                normal: "عادي",
                quality: "الجودة",
                loop: "تشغيل متكرر",
            };

            const player = new Plyr(video, {
                i18n: "{{ app()->getLocale() }}" === "ar" ? i18n_ar : {},
                controls: [
                    "play-large",
                    "rewind",
                    "play",
                    "fast-forward",
                    "progress",
                    "current-time",
                    "duration",
                    "mute",
                    "volume",
                    "settings",
                    "fullscreen",
                ],
                settings: ["quality", "speed", "captions"],
                tooltips: {
                    controls: true,
                    seek: true
                },
                quality: {
                    default: availableQualities[0] || 720,
                    options: availableQualities,
                    forced: true,
                    onChange: newQuality => {
                        if (hls) {
                            const level = hls.levels.findIndex(l => l.height === newQuality);
                            if (level !== -1) {
                                hls.currentLevel = level;
                            }
                        }
                    }
                }
            });

            video.addEventListener("canplay", () => {
                loader.style.display = "none";
            }, {
                once: true
            });
        }

        document.addEventListener("DOMContentLoaded", function() {
            videoPlayerIoRun();
        });
    </script>
@endpush
