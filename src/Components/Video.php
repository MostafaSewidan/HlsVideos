<?php
namespace HlsVideos\Components;

use Illuminate\View\Component;

class Video extends Component
{

    public function __construct(public $video, public $fullScreenStatus = 'on')
    {

    }

    public function render()
    {
        return view('hls-videos::components.video');
    }

}
