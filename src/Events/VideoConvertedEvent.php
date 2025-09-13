<?php
namespace  HlsVideos\Events;

class VideoConvertedEvent
{
    public function __construct(public $video, public $tenant)
    {
        //
    }
}
