<?php

namespace HlsVideos\Console;

use HlsVideos\Models\HlsVideo;
use HlsVideos\Services\VideoArchiveService;
use Illuminate\Console\Command;

/**
 * Restores one archived video of the current tenant from the Storage Box:
 *   tenants:artisan "hls-videos:unarchive <video_id> --test" --tenant=<id>
 *
 * --test restores a copy to hls-videos.archive.test_target and changes nothing on
 * the video. Without it the video must be archived, and comes back to its bucket.
 */
class UnArchiveVideo extends Command
{
    protected $signature = 'hls-videos:unarchive {video : hls_videos.id} {--test : restore a copy to the test bucket only}';

    protected $description = 'Restore one archived HLS video from the Storage Box';

    public function handle(VideoArchiveService $service): int
    {
        $video = HlsVideo::findOrFail($this->argument('video'));
        $service->setOutput(fn (string $line) => $this->line($line));

        $testTarget = null;
        if ($this->option('test')) {
            $testTarget = config('hls-videos.archive.test_target');
            if (! $testTarget) {
                $this->error('hls-videos.archive.test_target is not set');

                return self::FAILURE;
            }
        }

        $video = $service->unArchive($video, $testTarget);
        $this->info($testTarget
            ? "{$video->id}: restored a copy to {$testTarget}, the video is unchanged"
            : "{$video->id}: storage_state={$video->storage_state}");

        return self::SUCCESS;
    }
}
