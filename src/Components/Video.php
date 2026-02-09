<?php
namespace HlsVideos\Components;

use Illuminate\View\Component;

class Video extends Component
{
    public $videoType = 'hls';

    public function __construct(public $video, public $fullScreenStatus = 'on')
    {
        $this->videoType = $video->is_support_original ? 'direct' : 'hls';
    }

    public function render()
    {
        return view('hls-videos::components.video');
    }
}
