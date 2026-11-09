<?php

namespace HlsVideos\Console;

use HlsVideos\Models\HlsVideo;
use HlsVideos\Services\VideoCleanup;
use Illuminate\Console\Command;

/**
 * Removes the files and rows of videos trashed more than --days ago (a video is
 * trashed when it loses its last lesson). Works on the current tenant's
 * database: schedule it through `tenants:artisan "hls-videos:purge-trashed"`.
 */
class PurgeTrashedVideos extends Command
{
    protected $signature = 'hls-videos:purge-trashed {--days=7 : how long a video stays in the trash}';

    protected $description = 'Delete the files of HLS videos trashed more than N days ago';

    public function handle(VideoCleanup $cleanup): int
    {
        $days = max(1, (int) $this->option('days'));
        $count = 0;

        HlsVideo::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays($days))
            ->orderBy('deleted_at')
            ->select('id', 'deleted_at')
            ->chunk(200, function ($videos) use ($cleanup, &$count) {
                foreach ($videos as $video) {
                    $cleanup->deleteNow($video);
                    $count++;
                }
            });

        $this->info("Queued {$count} trashed video(s) for deletion.");

        return self::SUCCESS;
    }
}
