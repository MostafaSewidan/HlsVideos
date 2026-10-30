<script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
<script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>

@include('hls-videos::components._cdn-fallback')
@php
    $videoType = isset($videoType) ? $videoType : 'hls';
@endphp
<script>
    const videoType = '{{ $videoType }}';

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

            // hls.js keeps the playlist url (primary host) on each segment, so the
            // error alone can't tell which CDN was really hit. Remember it here.
            const cdnUsedFor = new Map();

            const hlsConfig = {
                xhrSetup: function(xhr, url) {
                    // Segments on a CDN: send them to the CDN in use, with no custom
                    // headers (they would force a CORS preflight the CDN may refuse).
                    if (CdnFallback.isCdnUrl(url)) {
                        cdnUsedFor.set(url, CdnFallback.current());
                        xhr.open('GET', CdnFallback.rewrite(url), true);
                        return;
                    }
                    @if (! auth('admin')->check())
                        @if (isset($authToken))
                            xhr.setRequestHeader("Authorization",
                                "{{ isset($authToken) ? $authToken : null }}");
                        @endif
                        @if (isset($password))
                            xhr.setRequestHeader("Password",
                                "{{ isset($password) ? $password : null }}");
                        @endif
                        @if (isset($version))
                            xhr.setRequestHeader("Version", "{{ isset($version) ? $version : null }}");
                        @endif
                        xhr.setRequestHeader("Accept", "application/json");
                    @endif
                }
            };

            // Only the first-byte timeout is changed, so a CDN that hangs is noticed
            // sooner. Retry counts stay at hls.js' defaults (4 / 6): slow, old tablets
            // need them, and fewer retries stop playback for good on a bad segment.
            if (CdnFallback.enabled) {
                hlsConfig.fragLoadPolicy = {
                    default: {
                        maxTimeToFirstByteMs: CdnFallback.config.fragTtfbMs,
                        maxLoadTimeMs: 120000,
                        timeoutRetry: {
                            maxNumRetry: 4,
                            retryDelayMs: 0,
                            maxRetryDelayMs: 0
                        },
                        errorRetry: {
                            maxNumRetry: 6,
                            retryDelayMs: 1000,
                            maxRetryDelayMs: 8000
                        }
                    }
                };
            }

            // Switch CDN only when a segment request got no answer: it timed out,
            // or the connection failed (status 0 = blocked / reset). Not for:
            // - requests hls.js cancels itself on a seek (those come as 'aborted',
            //   so the details check below already skips them),
            // - real answers like 404 / 403 (the other CDN has the same files),
            // - the viewer being offline (the other CDN won't help either).
            // Don't check stats.aborted: hls.js sets it on every timeout too.
            function segmentNeverArrived(data) {
                if (data.details !== Hls.ErrorDetails.FRAG_LOAD_ERROR &&
                    data.details !== Hls.ErrorDetails.FRAG_LOAD_TIMEOUT) return false;
                if (navigator.onLine === false) return false;
                if (data.details === Hls.ErrorDetails.FRAG_LOAD_TIMEOUT) return true;

                const status = data.response ? data.response.code : 0;
                return !status || status >= 500;
            }

            const hls = new Hls(hlsConfig);

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
                const failedUrl = (data.frag && data.frag.url) || (data.context && data.context.url) || null;
                const isCdnFailure = data.type === Hls.ErrorTypes.NETWORK_ERROR &&
                    failedUrl && CdnFallback.isCdnUrl(failedUrl) &&
                    segmentNeverArrived(data);

                // A request sent to the old CDN before the switch (e.g. one that hung
                // until its timeout) failing now says nothing about the CDN in use.
                // Without this check it would switch us back to the blocked CDN.
                const usedCdn = failedUrl ? cdnUsedFor.get(failedUrl) : undefined;
                if (isCdnFailure && usedCdn && usedCdn !== CdnFallback.current()) {
                    if (data.fatal) hls.startLoad(video.currentTime || -1);
                    return;
                }

                // First segment failure on a CDN: move to the other one. hls.js'
                // own retry then goes through xhrSetup and lands on the new host.
                if (isCdnFailure && CdnFallback.switchCdn({
                        reason: data.details,
                        player: 'hlsjs',
                        url: failedUrl,
                        httpStatus: data.response ? data.response.code : null,
                        position: video.currentTime || 0
                    })) {
                    if (data.fatal) hls.startLoad(video.currentTime || -1);
                    return;
                }

                if (data.fatal) {
                    console.error('Fatal HLS error:', data);
                    // Hand over to the browser's own HLS player only where one exists
                    // (Safari, new Chrome). Android WebViews / tablets have none, so
                    // destroying hls.js there would just leave a dead player.
                    if (video.canPlayType('application/vnd.apple.mpegurl')) {
                        hls.destroy();
                        fallbackToNative(videoSource);
                    }
                }
            });
        }
        // Native HLS with manual quality parsing
        else if (video.canPlayType("application/vnd.apple.mpegurl")) {

            // Parse manifest to get quality levels
            const qualities = await parseM3U8Manifest(CdnFallback.withCdnParam(videoSource));

            // Set initial source
            video.src = CdnFallback.withCdnParam(videoSource);

            video.addEventListener("loadedmetadata", () => {
                const qualityHeights = qualities.map(q => q.height);
                initPlyr(qualityHeights, null, qualities);
            }, {
                once: true
            });

            watchNativeCdn(video, 'native', function() {
                const quality = qualities.find(q => q.height === video.dataset.quality * 1);
                return CdnFallback.withCdnParam(quality && quality.url ? quality.url : videoSource);
            });
        }
    }

    async function fallbackToNative(videoSource) {
        const video = document.getElementById("player");

        if (video.canPlayType("application/vnd.apple.mpegurl")) {

            const qualities = await parseM3U8Manifest(CdnFallback.withCdnParam(videoSource));

            video.src = CdnFallback.withCdnParam(videoSource);

            video.addEventListener("loadedmetadata", () => {
                const qualityHeights = qualities.map(q => q.height);
                initPlyr(qualityHeights, null, qualities);
            }, {
                once: true
            });

            watchNativeCdn(video, 'native', function() {
                const quality = qualities.find(q => q.height === video.dataset.quality * 1);
                return CdnFallback.withCdnParam(quality && quality.url ? quality.url : videoSource);
            });
        }
    }

    function isIOS() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    }

    function initNativePlayer() {
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

        settingsOptions = ["speed", "captions"];

        let data = {
            i18n: "{{ app()->getLocale() }}" === "ar" ? i18n_ar : {},
            controls: controls,
            settings: settingsOptions,
            tooltips: {
                controls: true,
                seek: true
            }
        };

        const player = new Plyr(video, data);

        player.on('ready', (event) => {
            if (loader) {
                loader.style.display = "none";
            }
        });

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

    function adjustContainerForPortrait(video) {
        if (video.videoHeight > video.videoWidth) {
            const container = video.closest('.video-container');
            if (container) {
                container.style.aspectRatio = `${video.videoWidth} / ${video.videoHeight}`;
            }
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        const video = document.getElementById("player");
        if (video) {

            video.addEventListener('loadedmetadata', function() {
                adjustContainerForPortrait(video);
            });

            applyPosterFallback(video);

            if (videoType === 'hls') {
                videoPlayerIoRun();
            } else {
                // Direct mp4 from the CDN: starts on Cloudflare, moves to Bunny on failure.
                const mp4Source = video.querySelector('source');
                if (mp4Source && CdnFallback.isCdnUrl(mp4Source.src)) {
                    const originalSrc = mp4Source.src;
                    const trySwitch = watchNativeCdn(video, 'mp4', function() {
                        return CdnFallback.rewrite(originalSrc);
                    });
                    // A failing <source> child fires "error" on itself, not on the <video>.
                    mp4Source.addEventListener('error', function() {
                        trySwitch('mp4_source_error');
                    });
                }
                initNativePlayer();
            }
        }
    });

    function initPlyr(availableQualities, hls, nativeQualities) {
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

        const hasQualities = availableQualities && availableQualities.length > 0;
        const settingsOptions = hasQualities ? ["quality", "speed", "captions"] : ["speed", "captions"];

        const player = new Plyr(video, {
            i18n: "{{ app()->getLocale() }}" === "ar" ? i18n_ar : {},
            controls: controls,
            settings: settingsOptions,
            tooltips: {
                controls: true,
                seek: true
            },
            quality: hasQualities ? {
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

                            video.dataset.quality = newQuality;
                            video.src = CdnFallback.withCdnParam(quality.url);
                            video.currentTime = currentTime;

                            if (wasPlaying) {
                                video.play().catch(e => console.error('Play error:', e));
                            }
                        }
                    }
                }
            } : {}
        });

        player.on('ready', (event) => {
            if (loader) {
                loader.style.display = "none";
            }
        });

        // Store current quality for reference
        if (nativeQualities && hasQualities) {
            player.currentQuality = availableQualities[0];
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
</script>
