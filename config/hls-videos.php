<?php

use HlsVideos\Repositories\HlsFolderRepository;
use HlsVideos\Services\Qualities\Mp4ToService;
use HlsVideos\Services\Storages\R2StorageService;

return [
    'access_route_stream' => 'dashboard.video.stream',
    'uploader_access_middleware' => [],
    'uploader_access_url' => env('HLS_VIDEO_UPLOADER_ACCESS_URL', ''),
    'video_folder_middleware' => [],
    'mp4_to_token' => env("HLS_VIDEO_MP4_TO_TOKEN"),
    'temp_disk' => env("HLS_VIDEO_TEMP_DISK", 'temp_video'),
    'thumb_disk' => env("HLS_VIDEO_THUMB_DISK", 'thumbnails'),
    'stream_disk' => env("HLS_VIDEO_STREAM_DISK", 'r2'),

    /*
    |--------------------------------------------------------------------------
    | Disk that holds the ORIGINAL (pre-transcode) video file
    |--------------------------------------------------------------------------
    | Referenced by UploadToStepsLocalencoderService and by the direct upload
    | flow. Must be an S3-compatible disk (R2) when upload_driver is "direct".
    */
    'uploaded_videos_disk' => env("HLS_VIDEO_UPLOADED_DISK", 'r2'),

    /*
    |--------------------------------------------------------------------------
    | Prefix under which original videos are stored on the remote disk
    |--------------------------------------------------------------------------
    | Final key => {prefix}/{tenant->media_folder}/{video_id}/{file_name}
    | Do NOT change on an existing installation: already-uploaded originals
    | live under the old prefix.
    */
    'temp_videos_prefix' => env("HLS_VIDEO_TEMP_PREFIX", 'temp-videos'),

    'video_player_optionstatus' => true,

    /*
    |--------------------------------------------------------------------------
    | Student video player (<x-hls-video>)
    |--------------------------------------------------------------------------
    | player           plyr    = the current player (Plyr 3).
    |                  videojs = the new player (Video.js 10). Needs Chrome/WebView
    |                            111+ or Safari 16.4+; older tablets need plyr.
    | videojs.version  the Video.js CDN version. Every Video.js file is loaded
    |                  with this same version.
    | videojs.skin     default = modern, frosted look
    |                  neutral = lighter, closer to a classic control bar
    |                  compat  = plain, built for older browsers
    */
    'player' => env('HLS_VIDEO_PLAYER', 'plyr'),
    'videojs' => [
        'version' => env('HLS_VIDEO_VIDEOJS_VERSION', '10.2.0'),
        'skin' => env('HLS_VIDEO_PLAYER_SKIN', 'neutral'),
        'accent_color' => env('HLS_VIDEO_PLAYER_ACCENT_COLOR', '#ffc629'),
    ],
    'repositories' => [
        'hls_folder' => HlsFolderRepository::class
    ],
    'steps_encoder_token' => env("HLS_VIDEO_STEPS_ENCODER_TOKEN"),
    'local_server_password' => env("HLS_VIDEO_LOCAL_SERVER_PASSWORD"),
    'steps_encoder_urls' => [],
    'tenant_model' => null,
    'convert_quality_queue_name' => env("HLS_VIDEO_CONVERT_QUEUE", 'default'),

    // How many files are uploaded to R2 at the same time after a video is converted.
    'upload_concurrency' => (int) 5,

    // An encode that writes no new segment for this many minutes is taken as
    // frozen: ffmpeg is killed and the video fails right away instead of at the
    // 2-hour worker timeout. 0 turns the watchdog off.
    'encode_stall_minutes' => (int) env("HLS_ENCODE_STALL_MINUTES", 10),

    // Normalize variable/too-high frame rates inside the HLS encode instead of a
    // separate full re-encode first. Set to false to go back to the separate pass.
    'inline_fps_normalization' => (bool) env("HLS_VIDEO_INLINE_FPS_NORMALIZATION", true),

    // Sources above this frame rate are encoded at 30fps. 60 = keep 60fps videos
    // as they are; 30 = also bring 60fps recordings down to 30 (about half the encode time).
    'max_fps' => (int) env("HLS_VIDEO_MAX_FPS", 30),

    // Tenants that get a zip of the top quality after conversion. Everyone else
    // has their temp files removed as soon as the video is READY.
    'compress_tenant_ids' => array_map('intval', array_filter(explode(',', env("HLS_VIDEO_COMPRESS_TENANT_IDS", '11,42,48,5005')))),

    /*
    |--------------------------------------------------------------------------
    | Upload driver
    |--------------------------------------------------------------------------
    | "server" (default) -> legacy behaviour. The browser POSTs the whole file
    |                       to PHP, which stores it locally, extracts thumb and
    |                       duration, then streams it up to the remote disk.
    |
    | "direct"           -> the browser uploads straight to R2 using a signed
    |                       S3 multipart upload. The file never touches PHP.
    |                       Thumbnail and duration are supplied later by the
    |                       encoder node (see docs/DIRECT_UPLOAD.md).
    |
    | Nothing about the "server" path changes when this is left at its default,
    | so existing installations are unaffected.
    */
    'upload_driver' => env("HLS_VIDEO_UPLOAD_DRIVER", 'server'),

    'direct_upload' => [

        /*
        | Base URL for the five signing endpoints. Empty means "same origin as
        | the page", which is almost always what you want:
        |
        |   - the browser sends the session cookie, so route middleware such as
        |     auth: works normally
        |   - no CORS entry and no CSRF exemption are needed
        |   - the tenant resolves from the subdomain like every other request
        |
        | The separate upload host that `uploader_access_url` points at exists
        | because whole video files used to be POSTed to PHP. These calls are a
        | few hundred bytes of JSON, so they have no reason to leave the origin.
        */
        'base_url' => env("HLS_VIDEO_DIRECT_BASE_URL", ''),

        // Fixed size for every part except the last one. R2 requires all parts
        // to be equal in size, minimum 5 MiB, maximum 10,000 parts.
        'part_size' => (int) env("HLS_VIDEO_PART_SIZE", 32 * 1024 * 1024),

        // Hard ceiling enforced both in the browser and in /init.
        'max_file_size' => (int) env("HLS_VIDEO_MAX_FILE_SIZE", 1400 * 1024 * 1024),

        // Lifetime of each presigned UploadPart URL.
        'url_ttl_minutes' => (int) env("HLS_VIDEO_URL_TTL", 30),

        // Rate limit applied to the signing routes. The Laravel default of
        // 60/minute WILL break large uploads.
        'throttle' => env("HLS_VIDEO_UPLOAD_THROTTLE", '600,1'),

        // A pending upload older than this is probed with HeadObject: present
        // on R2 -> completed, absent -> left alone until abort_after_hours.
        'reconcile_after_minutes' => (int) env("HLS_VIDEO_RECONCILE_AFTER", 15),

        // A pending upload older than this is aborted and marked failed.
        'abort_after_hours' => (int) env("HLS_VIDEO_ABORT_AFTER", 24),

        // Optional authorization hook, called as fn($request, $folderId): bool
        // before a new upload is initialised. e.g. [Policy::class, 'canUpload']
        'authorize' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | MP4 exports (download a library video as MP4)
    |--------------------------------------------------------------------------
    | An export remuxes one HLS quality into an MP4 (no re-encode) and caches it.
    |
    | disk                 where the MP4s go. null = stream_disk. Files sit under
    |                      {media}/{video}/exports/{random}/, so DeleteHlsVideoFiles
    |                      removes them with the video, and the random part keeps
    |                      the path unguessable on a publicly served bucket.
    | ttl_days             how long a ready MP4 is kept; each new request for it
    |                      pushes this back. Run `hls-videos:prune-exports` daily.
    | queue                its own queue, so exports never delay encoding. Needs
    |                      a worker with ffmpeg and room for one video in temp_disk.
    | stale_after_minutes  a pending/processing export older than this is taken
    |                      as a dead worker and may be dispatched again.
    | middleware           MUST be the app's dashboard auth: the MP4 is not
    |                      encrypted.
    */
    'exports' => [
        'disk' => env('HLS_VIDEO_EXPORT_DISK'),
        'ttl_days' => (int) env('HLS_VIDEO_EXPORT_TTL_DAYS', 7),
        'url_ttl_minutes' => 60,
        'queue' => env('HLS_VIDEO_EXPORT_QUEUE', 'hls-video-exports'),
        'timeout' => 3600,
        'stale_after_minutes' => 120,
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | CDN fallback (Cloudflare -> Bunny)
    |--------------------------------------------------------------------------
    | Some networks in Egypt can't reach the Cloudflare stream domain
    | (storage_stream_domain). When segments fail to load there, the player
    | switches to backup_domain (a Bunny pull zone with the same paths) and
    | keeps playing from the same second. It stays on backup_domain until the
    | page is closed; every new video page starts on the primary CDN again.
    |
    | backup_domain                   empty = no fallback at all.
    | fragment_first_byte_timeout_ms  how long hls.js waits for a segment's first
    |                                 byte before treating the CDN as failing.
    | native_stall_seconds            iOS/Safari: how long playback may hang
    |                                 before switching.
    |
    | report: every switch is POSTed by the player to /hls/videos/cdn-switch,
    | then sent from the queue as a signed webhook to steps_monitoring.
    |   middleware   route middleware. Must NOT include CSRF/session (no web).
    |   user_guards  guards tried, in order, to find who was watching.
    */
    'cdn_fallback' => [
        'enabled' => (bool) env('HLS_VIDEO_CDN_FALLBACK', true),
        'backup_domain' => env('HLS_VIDEO_STREAM_BACKUP_URL', 'stream.stepsio.com'),
        'fragment_first_byte_timeout_ms' => (int) env('HLS_VIDEO_CDN_TTFB_MS', 8000),
        'native_stall_seconds' => (int) env('HLS_VIDEO_CDN_NATIVE_STALL_SECONDS', 12),
        // Before playing, load the thumb from Cloudflare; if it fails within this
        // time but loads from Bunny, the student starts on Bunny. 0 = off.
        'thumb_probe_timeout_ms' => (int) env('HLS_VIDEO_CDN_PROBE_MS', 4000),

        'report' => [
            'enabled' => (bool) env('HLS_VIDEO_CDN_REPORT', true),
            'middleware' => ['throttle:30,1'],
            'user_guards' => [],
            'webhook_url' => env('HLS_VIDEO_CDN_SWITCH_WEBHOOK_URL'),
            'webhook_secret' => env('HLS_VIDEO_CDN_SWITCH_WEBHOOK_SECRET'),
            'queue' => env('HLS_VIDEO_CDN_SWITCH_QUEUE', 'default'),
        ],
    ],

    'storages' => [
        'r2' => [
            'disk_name' => 'r2',
            'service' => R2StorageService::class
        ]
    ],
    'qualities' => [
        'original' => [
            'quality' => 'original',
            'convert_service' => Mp4ToService::class
        ]
    ]
];

