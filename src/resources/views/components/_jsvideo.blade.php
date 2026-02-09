<script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
<script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>

<script>
    videoType = '{{ isset($videoType) ? $videoType : 'hls' }}';
    async function parseM3U8Manifest(url) {
        try {
            const response = await fetch(url);
            const manifestText = await response.text();

            const qualities = [];
            const lines = manifestText.split('\n');

            for (let i = 0; i < lines.length; i++) {
                const line = lines[i].trim();

                // Look for stream info lines
                if (line.startsWith('#EXT-X-STREAM-INF:')) {
                    // Extract resolution
                    const resolutionMatch = line.match(/RESOLUTION=(\d+)x(\d+)/);
                    // Extract bandwidth for sorting
                    const bandwidthMatch = line.match(/BANDWIDTH=(\d+)/);

                    if (resolutionMatch) {
                        const width = parseInt(resolutionMatch[1]);
                        const height = parseInt(resolutionMatch[2]);
                        const bandwidth = bandwidthMatch ? parseInt(bandwidthMatch[1]) : 0;

                        // Get the variant URL (next non-comment line)
                        let variantUrl = '';
                        for (let j = i + 1; j < lines.length; j++) {
                            if (!lines[j].trim().startsWith('#')) {
                                variantUrl = lines[j].trim();
                                break;
                            }
                        }

                        // Make absolute URL if needed
                        if (variantUrl && !variantUrl.startsWith('http')) {
                            const baseUrl = url.substring(0, url.lastIndexOf('/') + 1);
                            variantUrl = baseUrl + variantUrl;
                        }

                        qualities.push({
                            height: height,
                            width: width,
                            bandwidth: bandwidth,
                            url: variantUrl
                        });
                    }
                }
            }

            // Sort by height (quality) descending
            qualities.sort((a, b) => b.height - a.height);

            // Remove duplicates based on height
            const uniqueQualities = qualities.filter((q, index, self) =>
                index === self.findIndex((t) => t.height === q.height)
            );

            return uniqueQualities;
        } catch (error) {
            console.error('Error parsing M3U8:', error);
            return [];
        }
    }

    function shouldUseWorker() {
        // 1. هل يدعم المتصفح Web Workers؟
        if (typeof Worker === 'undefined') return false;

        // 2. عدد الأنوية إن كانت متوفرة (heuristic)
        const cores = navigator.hardwareConcurrency || 1;
        if (cores >= 3) return true; // أجهزة حديثة غالبًا

        // 3. userAgent heuristics للـ Android WebView / أجهزة قديمة
        const ua = navigator.userAgent || '';
        // علامات WebView القديمة: "wv" أو "Version/<android_version>" أو قديمة Android 4/5
        const isAndroid = /Android/.test(ua);
        const isWV = /\bwv\b/i.test(ua); // Android WebView has "wv"
        const androidOld = /Android\s([0-9])/.exec(ua);
        const androidMajor = androidOld ? parseInt(androidOld[1], 10) : 999;

        if (isAndroid && (isWV || androidMajor <= 5)) {
            return false; // منع استخدام workers في WebView القديمة / اندرويد القديم
        }

        // 4. كخيار افتراضي: استخدم worker لو لم يظهر سبب لرفضه
        return true;
    }

    async function videoPlayerIoRun(source = null) {
        const video = document.getElementById("player");
        const videoSource = source ?? video.querySelector("source").src;

        // Try HLS.js first (works in most WebViews)
        if (Hls.isSupported()) {
            const hls = new Hls();

            hls.loadSource(videoSource);
            hls.attachMedia(video);

            hls.on(Hls.Events.MANIFEST_PARSED, function() {
                const availableQualities = hls.levels
                    .map(l => l.height)
                    .filter((v, i, a) => a.indexOf(v) === i)
                    .sort((a, b) => b - a);

                initPlyr(availableQualities, hls, null);
            });

            hls.on(Hls.Events.ERROR, function(event, data) {
                if (data.fatal) {
                    console.error('Fatal HLS error, falling back to native:', data);
                    fallbackToNative(videoSource);
                }
            });
        }
        // Native HLS with manual quality parsing
        else if (video.canPlayType("application/vnd.apple.mpegurl")) {

            // Parse manifest to get quality levels
            const qualities = await parseM3U8Manifest(videoSource);

            // Set initial source
            video.src = videoSource;

            video.addEventListener("loadedmetadata", () => {
                const qualityHeights = qualities.map(q => q.height);
                initPlyr(qualityHeights, null, qualities);
            });
        }
    }

    async function fallbackToNative(videoSource) {
        const video = document.getElementById("player");

        if (video.canPlayType("application/vnd.apple.mpegurl")) {

            const qualities = await parseM3U8Manifest(videoSource);

            video.src = videoSource;

            video.addEventListener("loadedmetadata", () => {
                const qualityHeights = qualities.map(q => q.height);
                initPlyr(qualityHeights, null, qualities);
            });
        }
    }

    function isIOS() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    }

    function initPlyr(availableQualities = [], hls = null, nativeQualities = null, videoType = 'hls') {
        const video = document.getElementById("player");
        const loader = document.getElementById("video-loader");
        const isIos = isIOS();

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
            auto: "تلقائي",
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

        // Add fullscreen based on your condition
        @if ((isset($fullScreenStatus) && $fullScreenStatus == 'on') || !isset($fullScreenStatus))
            controls.push("fullscreen");
        @endif

        if (videoType === 'hls') {

            const hasQualities = availableQualities && availableQualities.length > 0;
            const settingsOptions = hasQualities ? ["quality", "speed", "captions"] : ["speed", "captions"];
        } else {
            settingsOptions = ["speed", "captions"];
        }

        let data = {
            i18n: "{{ app()->getLocale() }}" === "ar" ? i18n_ar : {},
            controls: controls,
            settings: settingsOptions,
            tooltips: {
                controls: true,
                seek: true
            }
        };

        if (videoType === 'hls') {
            data.quality = hasQualities ? {
                default: availableQualities[0] || 720,
                options: availableQualities,
                forced: true,
                onChange: newQuality => {
                    const currentTime = video.currentTime;
                    const wasPlaying = !video.paused;

                    if (hls) {
                        // HLS.js quality switching
                        const level = hls.levels.findIndex(l => l.height === newQuality);
                        if (level !== -1) {
                            hls.currentLevel = level;
                        }
                    } else if (nativeQualities) {
                        // Native quality switching - change source
                        const quality = nativeQualities.find(q => q.height === newQuality);
                        if (quality && quality.url) {

                            video.src = quality.url;
                            video.currentTime = currentTime;

                            if (wasPlaying) {
                                video.play().catch(e => console.error('Play error:', e));
                            }
                        }
                    }
                }
            } : {};
        }

        player.on('ready', (event) => {
            if (loader) {
                loader.style.display = "none";
            }
        });

        if (videoType === 'hls') { // Store current quality for reference
            if (nativeQualities && hasQualities) {
                player.currentQuality = availableQualities[0];
            }
        }


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
        if ({{ $videoType }} === 'hls') {
            videoPlayerIoRun();
        } else {
            initPlyr([], null, null, 'native');
        }
    });
</script>
