<?php
namespace HlsVideos\Components;

use HlsVideos\Services\PlayerDebug;
use Illuminate\View\Component;

class Video extends Component
{
    // Video.js skins (hls-videos.videojs.skin): the CDN bundle and the tag it registers.
    private const VIDEOJS_SKINS = [
        'default' => ['bundle' => 'video.js', 'tag' => 'video-skin'],
        'neutral' => ['bundle' => 'video-neutral.js', 'tag' => 'video-neutral-skin'],
        'compat' => ['bundle' => 'video-compat.js', 'tag' => 'video-compat-skin'],
    ];

    public $videoType = 'hls';

    /** @var array{bundle: string, tag: string} */
    public array $videojsSkin;

    /** Debug logging for this viewer (PlayerDebug::clientConfig), null when off. */
    public ?array $playerDebug = null;

    /**
     * $debugViewer: who is watching (the student id), so hls-videos:debug-player
     * can log this viewer's player.
     */
    public function __construct(public $video, public $fullScreenStatus = 'on', public $password = null, public $authToken = null, public $version = null, public $debugViewer = null)
    {
        $this->playerDebug = PlayerDebug::clientConfig($debugViewer, $video);
        $this->videoType = $video->is_support_original ? 'direct' : 'hls';
        $this->videojsSkin = self::VIDEOJS_SKINS[config('hls-videos.videojs.skin')] ?? self::VIDEOJS_SKINS['default'];
    }

    public function render()
    {
        // hls-videos.player: 'plyr' (current) or 'videojs' (new player).
        return config('hls-videos.player') === 'videojs'
            ? view('hls-videos::components.videojs.video')
            : view('hls-videos::components.video');
    }
}
