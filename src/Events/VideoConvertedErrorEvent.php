<?php
namespace HlsVideos\Events;

class VideoConvertedErrorEvent
{
    public function __construct(public $video, public $tenant)
    {
        //
    }
}
