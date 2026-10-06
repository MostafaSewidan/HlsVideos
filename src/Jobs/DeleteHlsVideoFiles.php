<?php
namespace HlsVideos\Jobs;

use HlsVideos\DTOS\VideoConverted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use HlsVideos\Factories\VideoQualityProcessorFactory;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Models\HlsVideoQuality;
use HlsVideos\Services\VideoService;
use Illuminate\Support\Facades\Storage;

class DeleteHlsVideoFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 120, 600];

    public function __construct(public $videoId, protected $tenant) {}

    public function handle(): void
    {
        $this->tenant->makeCurrent();

        $path = VideoService::getMediaPath().$this->videoId;

        foreach (array_keys(config('hls-videos.storages')) as $disk) {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                continue;
            }

            throw_unless($storage->deleteDirectory($path),
                new \RuntimeException("Failed {$disk}:{$path}"));
        }

        $prefix = trim(config('hls-videos.temp_videos_prefix', 'temp-videos'), '/');
        Storage::disk(config('hls-videos.uploaded_videos_disk'))
            ->deleteDirectory("{$prefix}/{$path}");
    }
}