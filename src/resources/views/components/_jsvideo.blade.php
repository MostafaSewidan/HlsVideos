<script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
<script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>

<script>
    function videoPlayerIoRun(source = null) {
        const video = document.getElementById("player");

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
    }

    function isIOS() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
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

        const controls = [
            "play-large",
            "rewind",
            "play",
            "fast-forward",
            "progress",
            "current-time",
            "duration",
            "mute",
            "volume",
            "settings"
        ];

        @if ((isset($fullScreenStatus) && $fullScreenStatus == 'on') || !isset($fullScreenStatus))
            controls.push("fullscreen");
        @endif

        const player = new Plyr(video, {
            i18n: "{{ app()->getLocale() }}" === "ar" ? i18n_ar : {},
            controls: controls,
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
        player.on('ready', (event) => {
            loader.style.display = "none";
        });
        // video.addEventListener("canplay", () => {
        //     loader.style.display = "none";
        // }, {
        //     once: true
        // });


        @if (isset($fullScreenStatus) && $fullScreenStatus == 'off')
            if (isIOS()) {
                document.querySelector('.plyr').style.height = "100%";
            } else {
                document.querySelector('.plyr').style.height = "95%";
            }
            document.addEventListener('dblclick', function(event) {
                player.fullscreen.exit();
            });
            player.on('enterfullscreen', () => {
                if (player.fullscreen.active) {
                    player.fullscreen.exit();
                }
            });
            player.on('play', function() {
                PlayerState.postMessage('Playing');
            });

            player.on('pause', function() {
                PlayerState.postMessage('Paused');
            });
            player.on('controlsshown', (event) => {
                Controls.postMessage('controlsshown');
            });
            player.on('controlshidden', (event) => {
                Controls.postMessage('controlshidden');
            });
        @endif
    }

    document.addEventListener("DOMContentLoaded", function() {
        videoPlayerIoRun();
    });
</script>
