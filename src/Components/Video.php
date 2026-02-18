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
        return view('hls-videos::components.video');
    }
}
