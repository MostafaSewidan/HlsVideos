<?php

namespace HlsVideos\Services\Formats;

use FFMpeg\Format\Video\DefaultVideo;

class H264Nvenc extends DefaultVideo
{
    public function __construct(string $audioCodec = 'aac')
    {
        $this->audioCodec = $audioCodec;
    }

    public function getAvailableAudioCodecs(): array
    {
        return ['aac', 'mp3', 'libmp3lame'];
    }

    public function getAvailableVideoCodecs(): array
    {
        return ['h264_nvenc'];
    }

    public function getVideoCodec(): string
    {
        return 'h264_nvenc';
    }

    public function getExtraParams(): array
    {
        return [];
    }
}