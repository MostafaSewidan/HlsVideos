<?php

namespace  HlsVideos\Services\Contracts;

use  HlsVideos\DTOS\VideoConverted;
use  HlsVideos\Models\HlsVideoQuality;

interface VideoQualityProcessorInterface
{
   public function convertVideo($videoFile, HlsVideoQuality $quality): VideoConverted;
}
