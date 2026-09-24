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
    'repositories' => [
        'hls_folder' => HlsFolderRepository::class
    ],
    'steps_encoder_token' => env("HLS_VIDEO_STEPS_ENCODER_TOKEN"),
    'local_server_password' => env("HLS_VIDEO_LOCAL_SERVER_PASSWORD"),
    'steps_encoder_urls' => [],
    'tenant_model' => null,
    'convert_quality_queue_name' => env("HLS_VIDEO_CONVERT_QUEUE", 'default'),

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
        'max_file_size' => (int) env("HLS_VIDEO_MAX_FILE_SIZE", 3 * 1024 * 1024 * 1024),

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

