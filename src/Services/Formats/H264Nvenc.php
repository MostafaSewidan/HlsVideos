<?php

namespace HlsVideos\Services\Formats;

use FFMpeg\Format\Video\X264;

class H264Nvenc extends X264
{
    public function __construct(string $audioCodec = 'aac', string $videoCodec = 'h264_nvenc')
    {
        parent::__construct($audioCodec, $videoCodec);
    }

    public function getAvailableVideoCodecs(): array
    {
        return ['h264_nvenc'];
    }
}