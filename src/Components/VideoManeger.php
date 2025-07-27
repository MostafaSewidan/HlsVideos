<?php
namespace  HlsVideos\Components;

use Illuminate\View\Component;

class VideoManeger extends Component
{
    public $video;

    public function __construct(public $model)
    {
        $this->video = $model->getHlsVideo();
    }

    public function render()
    {
        return view('hls-videos::components.hls-video-maneger');
    }

}
