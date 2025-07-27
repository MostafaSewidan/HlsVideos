<?php

namespace  HlsVideos\Services\Contracts;

use  HlsVideos\Models\HlsVideoQuality;

interface VideoStorageInterface
{
   public function uploadVideo(HlsVideoQuality $quality,$storageConfig): bool;
}
