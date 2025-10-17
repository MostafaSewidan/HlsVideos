<?php

use HlsVideos\Repositories\HlsFolderRepository;
use HlsVideos\Services\Qualities\Mp4ToService;
use HlsVideos\Services\Storages\R2StorageService;

return [
    'access_route_stream' => 'dashboard.video.stream',
    'uploader_access_middleware' => [],
    'video_folder_middleware' => [],
    'mp4_to_token' => env("HLS_VIDEO_MP4_TO_TOKEN"),
    'temp_disk' => env("HLS_VIDEO_TEMP_DISK", 'temp_video'),
    'thumb_disk' => env("HLS_VIDEO_THUMB_DISK", 'thumbnails'),
    'stream_disk' => env("HLS_VIDEO_STREAM_DISK", 'r2'),
    'video_player_optionstatus' => true,
    'repositories' => [
        'hls_folder' => HlsFolderRepository::class
    ],
    'steps_encoder_token' => env("HLS_VIDEO_STEPS_ENCODER_TOKEN"),
    'steps_encoder_urls' => [],
    'tenant_model' => null,
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