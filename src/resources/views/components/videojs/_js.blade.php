@php($vjs = 'https://cdn.jsdelivr.net/npm/@videojs/cdn@' . config('hls-videos.videojs.version'))

{{-- Defines CdnFallback, watchNativeCdn and chooseStartCdn (shared with Plyr). --}}
@include('hls-videos::components._cdn-fallback')

{{-- Every Video.js file must come from the same version (shared chunks). --}}
<script type="module" src="{{ $vjs }}/i18n.js"></script>
@if (app()->getLocale() === 'ar')
    <script type="module" src="{{ $vjs }}/locales/ar.js"></script>
@endif
<script type="module" src="{{ $vjs }}/{{ $videojsSkin['bundle'] }}"></script>
@if ($videoType === 'hls')
    <script type="module" src="{{ $vjs }}/media/hlsjs-video.js"></script>
@endif

<script>
    (function() {
        const playerEl = document.getElementById('hls-vjs-player');
        const media = document.getElementById('player');
        if (!playerEl || !media) return;

        const isHls = media.tagName.toLowerCase() === 'hlsjs-video';
        const sourceUrl = media.dataset.src;
        const fullScreenAllowed = @json($fullScreenStatus !== 'off');

        // Portrait videos: let the container follow the video's shape.
        media.addEventListener('loadedmetadata', function() {
            if (media.videoHeight > media.videoWidth) {
                const container = playerEl.closest('.hls-vjs-container');
                if (container) container.style.aspectRatio = media.videoWidth + ' / ' + media.videoHeight;
            }
        });

        // Test the thumb first: a student who can't reach Cloudflare starts on
        // Bunny right away instead of waiting for a segment timeout.
        const hasMse = !!(window.MediaSource || window.ManagedMediaSource);
        chooseStartCdn(playerEl.getAttribute('poster'), isHls ? (hasMse ? 'hlsjs' : 'native') : 'mp4',
            function(url) {
                playerEl.setAttribute('poster', url);
            }).then(function() {
            if (isHls) {
                startHls();
            } else {
                startMp4();
            }
        });
        startPlayerBridge();

        /* ------------------------------------------------------------------ */
        /* HLS                                                                */
        /* ------------------------------------------------------------------ */

        function startHls() {
            // hls.js keeps the playlist url (primary host) on each segment, so the
            // error alone can't tell which CDN was really hit. Remember it here.
            const cdnUsedFor = new Map();

            const hlsJsConfig = {
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
                            xhr.setRequestHeader("Authorization", "{{ $authToken }}");
                        @endif
                        @if (isset($password))
                            xhr.setRequestHeader("Password", "{{ $password }}");
                        @endif
                        @if (isset($version))
                            xhr.setRequestHeader("Version", "{{ $version }}");
                        @endif
                        xhr.setRequestHeader("Accept", "application/json");
                    @endif
                }
            };

            // Only the first-byte timeout is changed, so a CDN that hangs is noticed
            // sooner. Retry counts stay at hls.js' defaults (4 / 6) for slow devices.
            if (CdnFallback.enabled) {
                hlsJsConfig.fragLoadPolicy = {
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

            // Switch CDN only when a segment got no answer: it timed out, or the
            // connection failed (status 0 = blocked / reset), or a 5xx. Not for
            // requests hls.js cancels itself on a seek (they come as 'aborted', so the
            // details check skips them), 4xx answers, or an offline device.
            // Don't check stats.aborted: hls.js sets it on every timeout too.
            function segmentNeverArrived(data) {
                if (data.details !== 'fragLoadError' && data.details !== 'fragLoadTimeOut') return false;
                if (navigator.onLine === false) return false;
                if (data.details === 'fragLoadTimeOut') return true;

                const status = data.response ? data.response.code : 0;
                return !status || status >= 500;
            }

            const hooked = new WeakSet();

            function hookEngine() {
                const hls = media.engine;
                if (!hls || hooked.has(hls)) return;
                hooked.add(hls);

                hls.on('hlsError', function(event, data) {
                    const failedUrl = (data.frag && data.frag.url) || (data.context && data.context.url) || null;
                    if (data.type !== 'networkError' || !failedUrl || !CdnFallback.isCdnUrl(failedUrl) ||
                        !segmentNeverArrived(data)) return;

                    // A request sent to Cloudflare before the switch failing now says
                    // nothing about Bunny. Just carry on.
                    const usedCdn = cdnUsedFor.get(failedUrl);
                    if (usedCdn && usedCdn !== CdnFallback.current()) {
                        if (data.fatal) hls.startLoad(media.currentTime || -1);
                        return;
                    }

                    // First failure: move to Bunny. hls.js' own retry then goes
                    // through xhrSetup and lands on the new host.
                    if (CdnFallback.switchCdn({
                            reason: data.details,
                            player: 'hlsjs',
                            url: failedUrl,
                            httpStatus: data.response ? data.response.code : null,
                            position: media.currentTime || 0
                        })) {
                        if (data.fatal) hls.startLoad(media.currentTime || -1);
                    }
                });
            }

            // hls.js plays through MSE (Android, desktop, iOS 17.1+). Where it can't,
            // Video.js plays the playlist natively (older iOS): no engine then, so
            // the native watcher reloads with ?cdn=backup on failure.
            let watchingNative = false;

            function onMediaStart() {
                const src = media.currentSrc || '';
                if (media.engine) {
                    hookEngine();
                } else if (!watchingNative && src && src.indexOf('blob:') !== 0) {
                    watchingNative = true;
                    watchNativeCdn(media, 'native', function() {
                        return CdnFallback.withCdnParam(sourceUrl);
                    });
                }
            }

            customElements.whenDefined('hlsjs-video').then(function() {
                ['loadstart', 'loadedmetadata'].forEach(function(evt) {
                    media.addEventListener(evt, onMediaStart);
                });

                media.source = {
                    src: CdnFallback.withCdnParam(sourceUrl),
                    // The stream url has no .m3u8 extension.
                    type: 'application/x-mpegURL',
                    preferPlayback: 'mse',
                    engine: {
                        hlsJs: hlsJsConfig
                    }
                };
                hookEngine();
            });
        }

        /* ------------------------------------------------------------------ */
        /* MP4                                                                */
        /* ------------------------------------------------------------------ */

        function startMp4() {
            if (!CdnFallback.isCdnUrl(sourceUrl)) {
                media.src = sourceUrl;
                return;
            }
            media.src = CdnFallback.rewrite(sourceUrl);
            watchNativeCdn(media, 'mp4', function() {
                return CdnFallback.rewrite(sourceUrl);
            });
        }

        /* ------------------------------------------------------------------ */
        /* Player state: poster, app bridge, fullscreen off                    */
        /* ------------------------------------------------------------------ */

        function startPlayerBridge() {
            const skin = playerEl.querySelector('.hls-vjs-skin');

            Promise.all([
                customElements.whenDefined('video-player'),
                customElements.whenDefined(skin.tagName.toLowerCase())
            ]).then(function() {
                const store = playerEl.store;
                if (!store) return;

                trimSkin(skin);
                blockPipAndCasting(store);

                if (fullScreenAllowed) return;

                // Same messages the Plyr player sends to the app (only when the app
                // keeps the player inline, full_screen = off).
                const last = {
                    paused: store.paused,
                    controlsVisible: store.controlsVisible
                };

                function post(channel, message) {
                    try {
                        if (window[channel] && window[channel].postMessage) window[channel].postMessage(message);
                    } catch (e) {}
                }

                store.subscribe(function() {
                    if (store.isFullscreen) store.exitFullscreen().catch(function() {});

                    if (store.paused !== last.paused) {
                        last.paused = store.paused;
                        post('PlayerState', store.paused ? 'Paused' : 'Playing');
                    }
                    if (store.controlsVisible !== last.controlsVisible) {
                        last.controlsVisible = store.controlsVisible;
                        post('Controls', store.controlsVisible ? 'controlsshown' : 'controlshidden');
                    }
                });
            });
        }

        // The skin renders its controls inside its own shadow root, out of reach of
        // page CSS. Add our rules there: no PiP / cast / AirPlay buttons, and no
        // fullscreen button when the app keeps the player inline. Also drop the
        // "i" (PiP) keyboard shortcut.
        function trimSkin(skin) {
            const root = skin.shadowRoot;
            if (!root) return;

            const hidden = ['media-pip-button', 'media-cast-button', 'media-airplay-button'];
            if (!fullScreenAllowed) hidden.push('media-fullscreen-button');

            try {
                const sheet = new CSSStyleSheet();
                sheet.replaceSync(hidden.join(',') + '{display:none!important}');
                root.adoptedStyleSheets = root.adoptedStyleSheets.concat([sheet]);
            } catch (e) {
                const style = document.createElement('style');
                style.textContent = hidden.join(',') + '{display:none!important}';
                root.appendChild(style);
            }

            function dropPipHotkeys() {
                root.querySelectorAll('media-hotkey[action="togglePictureInPicture"]').forEach(function(el) {
                    el.remove();
                });
            }
            dropPipHotkeys();
            new MutationObserver(dropPipHotkeys).observe(root, {
                childList: true,
                subtree: true
            });
        }

        // Picture-in-picture and casting are also off on the media element itself.
        // If either still starts somehow, leave PiP / pause the cast right away.
        function blockPipAndCasting(store) {
            store.subscribe(function() {
                if (store.isPictureInPicture) store.exitPictureInPicture().catch(function() {});
                if (store.remotePlaybackState && store.remotePlaybackState !== 'disconnected' && !store.paused) {
                    store.pause();
                }
            });
        }
    })();
</script>
