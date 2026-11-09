<?php
namespace HlsVideos\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Services\VideoService;
use Illuminate\Support\Facades\Storage;

class DeleteHlsVideoFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    public function __construct(public $videoId, protected $tenant)
    {
    }

    public function handle(): void
    {
        $this->tenant->makeCurrent();
        // Trashed videos are what this job is mostly for. A row already gone means
        // an earlier run finished.
        $video = $this->videoId ? HlsVideo::withTrashed()->find($this->videoId) : null;
        if ($video) {
            if ($video->archive_path) {
                // Deleting the copy on the Storage Box comes with automatic archiving (phase 5).
                \Log::warning("Deleting video {$video->id}: its archive on the box is left at {$video->archive_path}");
            }

            $path = VideoService::getMediaPath().$video->id;

            foreach (array_keys(config('hls-videos.storages')) as $disk) {
                $storage = Storage::disk($disk);

                if (! $storage->exists($path)) {
                    continue;
                }

                throw_unless($storage->deleteDirectory($path),
                    new \RuntimeException("Failed {$disk}:{$path}"));
            }

            // MP4 exports on a disk other than the storages above.
            foreach ($video->exports()->whereNotNull('path')->get() as $export) {
                Storage::disk($export->disk)->delete($export->path);
            }

            $prefix = trim(config('hls-videos.temp_videos_prefix', 'temp-videos'), '/');
            Storage::disk(config('hls-videos.uploaded_videos_disk'))
                ->deleteDirectory("{$prefix}/{$path}");
            $video->forceDelete();
        }
    }
}