{{--
    Player debug logging (hls-videos:debug-player), shared by the Plyr and Video.js
    players. Included before any other player script, so a script that fails to
    load is caught too. Always defines window.PlayerLog; it only records when
    $playerDebug is set (logging is on for this viewer).
--}}
<script>
    window.PlayerLog = (function(cfg) {
        const noop = function() {};
        if (!cfg) {
            return { enabled: false, add: noop, attachHls: noop, attachMedia: noop };
        }

        const startedAt = Date.now();
        const session = Math.random().toString(36).slice(2, 10) + startedAt.toString(36);
        const max = cfg.maxEvents || 4000;
        let queue = [];
        let seq = 0;
        let capped = false;

        function clip(value, len) {
            const text = typeof value === 'string' ? value : safeJson(value);
            return text && text.length > len ? text.slice(0, len) + '…' : text;
        }

        function safeJson(value) {
            try {
                if (value instanceof Error) return value.name + ': ' + value.message + (value.stack ? '\n' + value.stack : '');
                return JSON.stringify(value);
            } catch (e) {
                return String(value);
            }
        }

        function hostOf(url) {
            try {
                return new URL(url, window.location.href).host;
            } catch (e) {
                return null;
            }
        }

        function add(type, data) {
            if (seq >= max) {
                if (!capped) {
                    capped = true;
                    queue.push({ seq: ++seq, ms: Date.now() - startedAt, at: new Date().toISOString(), type: 'cap_reached', data: { max: max } });
                }
                return;
            }
            queue.push({ seq: ++seq, ms: Date.now() - startedAt, at: new Date().toISOString(), type: type, data: data || null });
            if (queue.length >= 100) flush();
        }

        function flush(leaving) {
            if (!queue.length) return;
            const body = JSON.stringify({ token: cfg.token, session: session, events: queue });
            queue = [];
            try {
                if (leaving && navigator.sendBeacon && navigator.sendBeacon(cfg.url, new Blob([body], { type: 'text/plain' }))) return;
            } catch (e) {}
            try {
                fetch(cfg.url, { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: body, keepalive: body.length < 60000 })
                    .catch(noop);
            } catch (e) {}
        }

        function connection() {
            const c = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
            return c ? { type: c.type, effectiveType: c.effectiveType, downlink: c.downlink, rtt: c.rtt, saveData: c.saveData } : null;
        }

        function bufferedAhead(media) {
            try {
                const t = media.currentTime;
                for (let i = 0; i < media.buffered.length; i++) {
                    if (media.buffered.start(i) <= t + 0.5 && media.buffered.end(i) >= t) return +(media.buffered.end(i) - t).toFixed(1);
                }
            } catch (e) {}
            return 0;
        }

        function mediaState(media) {
            return {
                t: media ? +(+media.currentTime || 0).toFixed(1) : null,
                paused: media ? media.paused : null,
                readyState: media ? media.readyState : null,
                networkState: media ? media.networkState : null,
                ahead: media ? bufferedAhead(media) : null,
                cdn: window.CdnFallback ? CdnFallback.current() : null,
                online: navigator.onLine,
                visible: document.visibilityState
            };
        }

        // Media element: every state change, with where playback is.
        const attachedMedia = new WeakSet();

        function attachMedia(media, label) {
            if (!media || attachedMedia.has(media)) return;
            attachedMedia.add(media);
            ['loadstart', 'loadedmetadata', 'loadeddata', 'canplay', 'playing', 'play', 'pause', 'waiting', 'stalled',
                'suspend', 'seeking', 'seeked', 'ended', 'emptied', 'abort', 'ratechange'
            ].forEach(function(evt) {
                media.addEventListener(evt, function() {
                    add('media.' + evt, Object.assign({ player: label, src: clip(media.currentSrc || '', 300) }, mediaState(media)));
                });
            });
            media.addEventListener('error', function() {
                const err = media.error;
                add('media.error', Object.assign({
                    player: label,
                    code: err ? err.code : null,
                    message: err ? err.message : null,
                    src: clip(media.currentSrc || '', 300)
                }, mediaState(media)));
            });
            setInterval(function() {
                if (document.visibilityState === 'visible') add('heartbeat', Object.assign({ player: label }, mediaState(media)));
            }, 10000);
        }

        // hls.js: playlists, every segment (host, time to first byte, size), every
        // error. Event names as strings: Video.js has no global Hls.
        const attachedHls = new WeakSet();

        function attachHls(hls, label) {
            if (!hls || attachedHls.has(hls)) return;
            attachedHls.add(hls);
            const Hls = window.Hls;
            add('hls.attached', { player: label, version: (hls.constructor && hls.constructor.version) || (Hls && Hls.version) || null });

            function timing(stats) {
                const l = stats && (stats.loading || stats);
                if (!l || !l.start) return null;
                return { ttfb: l.first ? Math.round(l.first - l.start) : null, total: l.end ? Math.round(l.end - l.start) : null, bytes: stats.loaded || stats.total || null };
            }

            function realUrl(data, fallback) {
                const nd = data && data.networkDetails;
                return (nd && (nd.responseURL || nd.url)) || fallback || null;
            }

            hls.on('hlsManifestLoading', function(e, data) {
                add('hls.manifest_loading', { url: clip(data.url, 300) });
            });
            hls.on('hlsManifestLoaded', function(e, data) {
                add('hls.manifest_loaded', { levels: data.levels ? data.levels.length : null, timing: timing(data.stats) });
            });
            hls.on('hlsManifestParsed', function(e, data) {
                add('hls.manifest_parsed', { heights: (data.levels || []).map(function(l) { return l.height; }) });
            });
            hls.on('hlsLevelLoaded', function(e, data) {
                add('hls.level_loaded', { level: data.level, host: hostOf(realUrl(data, data.details && data.details.url)), frags: data.details ? data.details.fragments.length : null, timing: timing(data.stats) });
            });
            hls.on('hlsLevelSwitched', function(e, data) {
                add('hls.level_switched', { level: data.level, height: hls.levels && hls.levels[data.level] ? hls.levels[data.level].height : null });
            });
            hls.on('hlsKeyLoaded', function(e, data) {
                add('hls.key_loaded', { host: hostOf(data.frag && data.frag.decryptdata && data.frag.decryptdata.uri) });
            });
            hls.on('hlsFragLoaded', function(e, data) {
                const frag = data.frag || {};
                add('hls.frag_loaded', { sn: frag.sn, level: frag.level, host: hostOf(realUrl(data, frag.url)), timing: timing(frag.stats || data.stats) });
            });
            hls.on('hlsError', function(e, data) {
                const frag = data.frag || {};
                const url = (frag && frag.url) || (data.context && data.context.url) || data.url || null;
                add('hls.error', {
                    type: data.type,
                    details: data.details,
                    fatal: !!data.fatal,
                    status: data.response ? data.response.code : null,
                    statusText: data.response ? clip(data.response.text, 200) : null,
                    url: clip(url, 300),
                    realHost: hostOf(realUrl(data, null)),
                    sn: frag.sn,
                    level: frag.level,
                    reason: data.reason || (data.error ? clip(data.error, 300) : null),
                    timing: timing(frag.stats || data.stats),
                    cdn: window.CdnFallback ? CdnFallback.current() : null,
                    online: navigator.onLine
                });
            });
            hls.on('hlsDestroying', function() {
                add('hls.destroying', null);
            });
        }

        // Anything that breaks: JS errors, unhandled promises, scripts / images
        // that fail to load (capture phase catches resource errors).
        window.addEventListener('error', function(e) {
            const target = e.target;
            if (target && target !== window && (target.src || target.href)) {
                add('resource_error', { tag: target.tagName, url: clip(target.src || target.href, 300) });
                return;
            }
            add('js_error', { message: clip(e.message, 500), source: clip(e.filename, 300), line: e.lineno, col: e.colno, stack: e.error ? clip(e.error.stack, 1500) : null });
        }, true);
        window.addEventListener('unhandledrejection', function(e) {
            add('promise_rejection', { reason: clip(e.reason, 1500) });
        });

        ['error', 'warn'].forEach(function(level) {
            const original = console[level];
            console[level] = function() {
                try {
                    add('console.' + level, { message: clip(Array.prototype.map.call(arguments, function(a) { return typeof a === 'string' ? a : safeJson(a); }).join(' '), 1500) });
                } catch (e) {}
                return original.apply(console, arguments);
            };
        });

        window.addEventListener('online', function() { add('network.online', connection()); });
        window.addEventListener('offline', function() { add('network.offline', null); });
        document.addEventListener('visibilitychange', function() {
            add('page.' + document.visibilityState, null);
            if (document.visibilityState === 'hidden') flush(true);
        });
        window.addEventListener('pagehide', function() {
            add('page.hide', null);
            flush(true);
        });

        // Scripts, styles and images the page loaded, and how long each took.
        window.addEventListener('load', function() {
            try {
                const entries = performance.getEntriesByType('resource')
                    .filter(function(r) { return ['script', 'link', 'css', 'img'].indexOf(r.initiatorType) !== -1; })
                    .map(function(r) {
                        return { type: r.initiatorType, url: clip(r.name, 200), ms: Math.round(r.duration), bytes: r.transferSize, status: r.responseStatus };
                    });
                add('page.resources', entries);
            } catch (e) {}
        });

        add('session', {
            page: location.host + location.pathname,
            ua: navigator.userAgent,
            platform: navigator.platform,
            lang: navigator.language,
            screen: screen.width + 'x' + screen.height + '@' + (window.devicePixelRatio || 1),
            cores: navigator.hardwareConcurrency || null,
            memory: navigator.deviceMemory || null,
            connection: connection(),
            online: navigator.onLine,
            mse: !!(window.MediaSource || window.ManagedMediaSource),
            nativeHls: !!document.createElement('video').canPlayType('application/vnd.apple.mpegurl')
        });

        setInterval(flush, 5000);

        return { enabled: true, add: add, attachHls: attachHls, attachMedia: attachMedia, flush: flush, state: mediaState };
    })(@json($playerDebug ?? null));
</script>
