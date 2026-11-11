# CDN fallback (Cloudflare → backup server)

Some networks in Egypt sometimes can't reach the Cloudflare stream domain
(`storage_stream_domain`, e.g. `stepsio-stream.org`). When that happens the player
switches the viewer to our backup server (`cdn_fallback.backup_domain`,
`streamer.stepsio.com`) and keeps playing from the same second.

The backup is an nginx reverse proxy + cache on a Hetzner server (managed in
Ploi, site `streamer.stepsio.com`). It pulls from `stepsio-stream.org` (R2 behind
Cloudflare) on a cache miss, so students reach it without going through
Cloudflare. It used to be a Bunny pull zone on `stream.stepsio.com`; that
hostname still points to Bunny, so it must not be used as `backup_domain`.

## How it works

Playlists (`index.m3u8`, `vd.m3u8`) and `secret.key` always come from our API.
Only segments (`.ts`), the poster, and direct mp4 files come from the CDN, so the
fallback only swaps the CDN host in those URLs.

| Player | Switches when | How |
|---|---|---|
| hls.js (Android / WebView / desktop) | first segment from a CDN host that times out or gets no answer (`fragLoadPolicy.maxTimeToFirstByteMs` = `fragment_first_byte_timeout_ms`) | `xhrSetup` rewrites the host of every CDN request. hls.js' own retry then goes to the new host. |
| Native HLS (iOS / Safari) | `<video>` network error, or no progress for `native_stall_seconds` while playing | reloads with `?cdn=backup`. The server then writes backup segment links into `vd.m3u8`, and the master carries the param to every quality. |
| mp4 (`videoType` ≠ hls) | same as native | swaps the host of the mp4 URL |
| Poster | image fails to load | swaps the poster host only (a missing thumbnail looks the same as a blocked one, so it doesn't move the video) |

- Every video page starts on Cloudflare. The switch happens once, Cloudflare →
  backup, and the rest of the video plays from the backup. It never goes back to
  Cloudflare on the same page. Closing the video and opening it again (or opening
  another video) starts on Cloudflare again. Nothing is remembered on the device.
- hls.js switches only when a segment got no answer: a timeout, status 0 (blocked /
  reset) or 5xx. Requests hls.js cancels itself (every seek does this), 4xx answers,
  and an offline device don't count. A late failure from a request sent to
  Cloudflare before the switch is ignored.
- Custom headers (`Authorization`, `Password`, `Version`) are now sent only to our
  API, not to CDN hosts. They aren't needed there, and they would force a CORS
  preflight.

## Reporting

Each switch is sent by the player to `POST /hls/videos/cdn-switch` (route
`hls.videos.cdn-switch`, outside the `web` group). `ReportCdnSwitchJob` then sends it
from the queue as a signed webhook to steps_monitoring `POST /hooks/cdn-switch`
(`X-Autoscaler-Timestamp`, `X-Autoscaler-Signature: sha256=HMAC("<ts>.<body>")`).
If the webhook isn't configured, the event is written to the log instead.

## Configuration (`hls-videos.cdn_fallback`)

| Key | Env | Default |
|---|---|---|
| `enabled` | `HLS_VIDEO_CDN_FALLBACK` | `true` |
| `backup_domain` | `HLS_VIDEO_STREAM_BACKUP_URL` | `streamer.stepsio.com` (empty = off) |
| `fragment_first_byte_timeout_ms` | `HLS_VIDEO_CDN_TTFB_MS` | `8000` |
| `native_stall_seconds` | `HLS_VIDEO_CDN_NATIVE_STALL_SECONDS` | `12` |
| `report.enabled` | `HLS_VIDEO_CDN_REPORT` | `true` |
| `report.middleware` | – | `['throttle:30,1']`. Never `web`. |
| `report.user_guards` | – | `[]` (base_backend: `['api-client']`) |
| `report.webhook_url` | `HLS_VIDEO_CDN_SWITCH_WEBHOOK_URL` | – |
| `report.webhook_secret` | `HLS_VIDEO_CDN_SWITCH_WEBHOOK_SECRET` | – (same value as steps_monitoring `CDN_SWITCH_WEBHOOK_SECRET`) |
| `report.queue` | `HLS_VIDEO_CDN_SWITCH_QUEUE` | `default` |

With `config:cache`, the app's own `config/hls-videos.php` must contain this section.

## Backup server checklist

The nginx config lives on the server, not in this repo (Ploi → server `cdn` →
site `streamer.stepsio.com` → nginx config).

- Same paths as the R2 public bucket:
  `https://streamer.stepsio.com/<media_folder>/<video>/<quality>/index-0.ts`, the
  thumbnail, and `temp-videos/...` for mp4. nginx proxies everything to
  `https://stepsio-stream.org` with `Host` and SNI set to that name.
- CORS: every response carries `Access-Control-Allow-Origin: *` (hls.js loads
  segments with XHR). The headers are set once at `server` level, so a `location`
  must not use `add_header`, or it drops them all.
- Cache: `proxy_cache hls`, key `$uri` (query strings ignored), segments 30 days,
  playlists 1 hour, 404s 1 minute. `X-Cache` shows HIT/MISS.
- Disk: the cache has its own volume. `/etc/nginx/conf.d/hls-cache.conf`:
  `proxy_cache_path /mnt/HC_Volume_107261624/hls levels=1:2 keys_zone=hls:200m
  max_size=170g min_free=15g inactive=14d use_temp_path=off;`. A volume ~90% full
  is normal: the cache is full and nginx drops the least used files.
- Check: `curl -I https://streamer.stepsio.com/<path to a segment>` twice. Expect
  `200`, `access-control-allow-origin: *`, and `x-cache: MISS` then `HIT`.
- Test: block `stepsio-stream.org` (DevTools → Network request blocking, or
  `/etc/hosts`) and play a video. Expect a single `[CDN] switching primary -> backup`
  in the console, playback continuing from `streamer.stepsio.com`, and one event in
  steps_monitoring.
