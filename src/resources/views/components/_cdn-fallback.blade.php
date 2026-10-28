{{-- CDN fallback (Cloudflare -> Bunny), shared by the Plyr and Video.js players. --}}
@php
    $cdnFallback = config('hls-videos.cdn_fallback', []);
    $cdnConfig = [
        'enabled' => ($cdnFallback['enabled'] ?? true) && filled($cdnFallback['backup_domain'] ?? null),
        'hosts' => [
            'primary' => config('hls-videos.storage_stream_domain'),
            'backup' => $cdnFallback['backup_domain'] ?? null,
        ],
        'fragTtfbMs' => (int) ($cdnFallback['fragment_first_byte_timeout_ms'] ?? 8000),
        'nativeStallSeconds' => (int) ($cdnFallback['native_stall_seconds'] ?? 12),
        'reportUrl' => ($cdnFallback['report']['enabled'] ?? true) && \Illuminate\Support\Facades\Route::has('hls.videos.cdn-switch')
            ? route('hls.videos.cdn-switch')
            : null,
        'videoId' => isset($video) ? $video->id : null,
        'authToken' => isset($authToken) ? $authToken : null,
    ];
@endphp
<script>
    /*
     * CDN fallback: Cloudflare (primary) -> Bunny (backup).
     * Both CDNs serve the same paths, so switching is just swapping the host.
     * Every video page starts on Cloudflare. On the first failure it moves to
     * Bunny for the rest of this page and never goes back. Opening the video
     * again (or another video) starts on Cloudflare again.
     */
    const CdnFallback = (function(cfg) {
        let current = 'primary';

        // Older versions remembered Bunny on the device. Drop that.
        try {
            localStorage.removeItem('hls_cdn_preference');
        } catch (e) {}

        function hostOf(url) {
            try {
                return new URL(url, window.location.href).host;
            } catch (e) {
                return null;
            }
        }

        function isCdnUrl(url) {
            const host = hostOf(url);
            return !!host && (host === cfg.hosts.primary || host === cfg.hosts.backup);
        }

        // Point a CDN url at the CDN currently in use. Other urls are returned as-is.
        function rewrite(url) {
            if (!cfg.enabled || !isCdnUrl(url)) return url;
            const target = cfg.hosts[current];
            const host = hostOf(url);
            if (host === target) return url;
            const parsed = new URL(url, window.location.href);
            parsed.host = target;
            return parsed.toString();
        }

        // Our own playlist urls (master / vd.m3u8): add ?cdn=backup so the server
        // writes Bunny segment links. Used by the native (iOS) player.
        function withCdnParam(url) {
            if (!cfg.enabled || !url) return url;
            try {
                const parsed = new URL(url, window.location.href);
                if (current === 'backup') parsed.searchParams.set('cdn', 'backup');
                else parsed.searchParams.delete('cdn');
                return parsed.toString();
            } catch (e) {
                return url;
            }
        }

        // Moves to Bunny, once. Returns false when already on Bunny.
        function switchCdn(details) {
            if (!canSwitch()) return false;
            current = 'backup';
            console.warn('[CDN] switching primary -> backup', details);
            report('primary', 'backup', details || {});
            return true;
        }

        function canSwitch() {
            return cfg.enabled && current === 'primary';
        }

        function report(from, to, details) {
            if (!cfg.reportUrl || !cfg.videoId) return;
            const headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            };
            if (cfg.authToken) headers['Authorization'] = cfg.authToken;
            try {
                fetch(cfg.reportUrl, {
                    method: 'POST',
                    headers: headers,
                    keepalive: true,
                    body: JSON.stringify({
                        video_id: cfg.videoId,
                        from_cdn: from,
                        to_cdn: to,
                        reason: details.reason || 'unknown',
                        http_status: details.httpStatus ?? null,
                        failed_url: details.url || null,
                        player: details.player || 'hlsjs',
                        position_seconds: details.position ?? null
                    })
                }).catch(function() {});
            } catch (e) {}
        }

        return {
            enabled: cfg.enabled,
            config: cfg,
            current: function() {
                return current;
            },
            isCdnUrl: isCdnUrl,
            rewrite: rewrite,
            withCdnParam: withCdnParam,
            switchCdn: switchCdn,
            canSwitch: canSwitch
        };
    })(@json($cdnConfig));

    // Native players (iOS / mp4): swap the source and continue from the same second.
    function reloadNativeSource(video, newSrc) {
        const position = video.currentTime || 0;
        const wasPlaying = !video.paused || video.autoplay;
        video.addEventListener('loadedmetadata', function() {
            if (position > 0) video.currentTime = position;
            if (wasPlaying) video.play().catch(function() {});
        }, {
            once: true
        });
        video.src = newSrc;
        video.load();
    }

    // Watches a native <video> and switches CDN on a network error, or when it
    // hangs while the viewer wants it playing. getSource() returns the source
    // for the current CDN.
    function watchNativeCdn(video, player, getSource) {
        if (!CdnFallback.enabled) return function() {};

        const stallMs = Math.max(3, CdnFallback.config.nativeStallSeconds) * 1000;
        let stallTimer = null;
        let hasPlayed = false;

        function trySwitch(reason) {
            clearTimeout(stallTimer);
            if (!CdnFallback.switchCdn({
                    reason: reason,
                    player: player,
                    url: video.currentSrc || null,
                    httpStatus: null,
                    position: video.currentTime || 0
                })) return;
            reloadNativeSource(video, getSource());
        }

        // Only while the viewer wants it playing: iOS loads nothing before "play",
        // so a timer started on page load would switch for no reason.
        function armStall(reason) {
            if (video.paused || !CdnFallback.canSwitch()) return;
            clearTimeout(stallTimer);
            stallTimer = setTimeout(function() {
                if (!video.paused) trySwitch(reason);
            }, stallMs);
        }

        video.addEventListener('error', function() {
            const err = video.error;
            // 2 = MEDIA_ERR_NETWORK, 4 = MEDIA_ERR_SRC_NOT_SUPPORTED (also what a failed load looks like)
            if (err && (err.code === 2 || err.code === 4)) trySwitch('native_error_' + err.code);
        });
        video.addEventListener('play', function() {
            if (!hasPlayed) armStall('native_startup_timeout');
        });
        video.addEventListener('waiting', function() {
            armStall(hasPlayed ? 'native_stalled' : 'native_startup_timeout');
        });
        video.addEventListener('stalled', function() {
            armStall(hasPlayed ? 'native_stalled' : 'native_startup_timeout');
        });
        ['playing', 'timeupdate'].forEach(function(evt) {
            video.addEventListener(evt, function() {
                if (!video.paused && video.currentTime > 0) hasPlayed = true;
                clearTimeout(stallTimer);
            });
        });
        video.addEventListener('pause', function() {
            clearTimeout(stallTimer);
        });

        return trySwitch;
    }

    // Poster: if the image fails on one CDN, try the other one. Doesn't change
    // the video's CDN (a missing thumbnail looks the same as a blocked one).
    function applyPosterFallback(video) {
        if (!CdnFallback.enabled || !video.poster || !CdnFallback.isCdnUrl(video.poster)) return;
        const preferred = CdnFallback.rewrite(video.poster);
        const img = new Image();
        img.onload = function() {
            video.poster = preferred;
        };
        img.onerror = function() {
            const u = new URL(preferred);
            u.host = u.host === CdnFallback.config.hosts.primary ?
                CdnFallback.config.hosts.backup :
                CdnFallback.config.hosts.primary;
            video.poster = u.toString();
        };
        img.src = preferred;
    }
</script>
