<?php
namespace  HlsVideos\Traits;

use HlsVideos\Models\HlsVideo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasHlsVideo
{
    public function hlsVideos(): MorphToMany
    {
        return $this->morphToMany(HlsVideo::class, 'videoable','hls_videoables','videoable_id','hls_video_id');
    }

    public function hlsVideo()
    {
        return $this->hlsVideos()->limit(1);
    }

    public function getHlsVideo()
    {
        return $this->hlsVideos()->first();
    }

    public function readyHlsVideo()
    {
        return $this->hlsVideos()->Ready()->limit(1);
    }

    public function getReadyHlsVideo()
    {
        return $this->hlsVideos()->Ready()->first();
    }
}