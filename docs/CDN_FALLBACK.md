# CDN fallback (Cloudflare → Bunny)

Some networks in Egypt sometimes can't reach the Cloudflare stream domain
(`storage_stream_domain`, e.g. `stepsio-stream.org`). When that happens the player
switches the viewer to the Bunny pull zone (`cdn_fallback.backup_domain`, e.g.
`stream.stepsio.com`) and keeps playing from the same second.

## How it works

Playlists (`index.m3u8`, `vd.m3u8`) and `secret.key` always come from our API.
Only segments (`.ts`), the poster, and direct mp4 files come from the CDN, so the
fallback only swaps the CDN host in those URLs.

| Player | Switches when | How |
|---|---|---|
| hls.js (Android / WebView / desktop) | first network error or timeout on a segment from a CDN host (`fragLoadPolicy.maxTimeToFirstByteMs` = `fragment_first_byte_timeout_ms`) | `xhrSetup` rewrites the host of every CDN request. hls.js' own retry then goes to the new host. |
| Native HLS (iOS / Safari) | `<video>` network error, or no progress for `native_stall_seconds` while playing | reloads with `?cdn=backup`. The server then writes backup segment links into `vd.m3u8`, and the master carries the param to every quality. |
| mp4 (`videoType` ≠ hls) | same as native | swaps the host of the mp4 URL |
| Poster | image fails to load | swaps the poster host only (a missing thumbnail looks the same as a blocked one, so it doesn't move the video) |

- It switches at most once per direction per page (Cloudflare → Bunny, and Bunny →
  Cloudflare if Bunny then fails), so it can't loop.
- After a switch to Bunny, the device starts on Bunny for `remember_hours`
  (localStorage `hls_cdn_preference`). Switching back to Cloudflare clears it.
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
| `backup_domain` | `HLS_VIDEO_STREAM_BACKUP_URL` | `stream.stepsio.com` (empty = off) |
| `remember_hours` | `HLS_VIDEO_CDN_REMEMBER_HOURS` | `24` |
| `fragment_first_byte_timeout_ms` | `HLS_VIDEO_CDN_TTFB_MS` | `8000` |
| `native_stall_seconds` | `HLS_VIDEO_CDN_NATIVE_STALL_SECONDS` | `12` |
| `report.enabled` | `HLS_VIDEO_CDN_REPORT` | `true` |
| `report.middleware` | – | `['throttle:30,1']`. Never `web`. |
| `report.user_guards` | – | `[]` (base_backend: `['api-client']`) |
| `report.webhook_url` | `HLS_VIDEO_CDN_SWITCH_WEBHOOK_URL` | – |
| `report.webhook_secret` | `HLS_VIDEO_CDN_SWITCH_WEBHOOK_SECRET` | – (same value as steps_monitoring `CDN_SWITCH_WEBHOOK_SECRET`) |
| `report.queue` | `HLS_VIDEO_CDN_SWITCH_QUEUE` | `default` |

With `config:cache`, the app's own `config/hls-videos.php` must contain this section.

## Bunny checklist

- The pull zone serves the same paths as the R2 public bucket:
  `https://stream.stepsio.com/<media_folder>/<video>/<quality>/index-0.ts`, the
  thumbnail, and `temp-videos/...` for mp4.
- CORS: responses carry `Access-Control-Allow-Origin` (hls.js loads segments with
  XHR). Bunny → Pull Zone → Headers → "Add CORS headers" with `ts`, `m3u8`, `jpg`,
  `mp4` in the extension list.
- Test: block `stepsio-stream.org` (DevTools → Network request blocking, or
  `/etc/hosts`) and play a video. Expect a single `[CDN] switching primary -> backup`
  in the console, playback continuing, and one event in steps_monitoring.
