<?php
namespace HlsVideos\Components;

use Illuminate\View\Component;

class Video extends Component
{
    public $videoType = 'hls';

    public function __construct(public $video, public $fullScreenStatus = 'on', public $password = null, public $authToken = null, public $version = null)
    {
        $this->videoType = $video->is_support_original ? 'direct' : 'hls';
    }

    public function render()
    {
        // hls-videos.player: 'plyr' (current) or 'videojs' (new player).
        return config('hls-videos.player') === 'videojs'
            ? view('hls-videos::components.videojs.video')
            : view('hls-videos::components.video');
    }
}
