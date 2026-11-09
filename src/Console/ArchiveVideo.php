<?php

namespace HlsVideos\Console;

use HlsVideos\Archive\VideoArchiver;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Services\VideoArchiveService;
use Illuminate\Console\Command;

/**
 * Archives one video of the current tenant to the Storage Box:
 *   tenants:artisan "hls-videos:archive <video_id> --dry-run" --tenant=<id>
 *
 * --dry-run only lists the video's files on R2 and checks its playlists.
 * Segments are deleted from R2 only when hls-videos.archive.delete_enabled is on.
 */
class ArchiveVideo extends Command
{
    protected $signature = 'hls-videos:archive {video : hls_videos.id} {--dry-run : list and check the files, change nothing}';

    protected $description = 'Archive one HLS video to the Storage Box';

    public function handle(VideoArchiveService $service): int
    {
        $video = HlsVideo::findOrFail($this->argument('video'));
        $service->setOutput(fn (string $line) => $this->line($line));

        if ($this->option('dry-run')) {
            $listing = $service->inspect($video);
            $this->table(['group', 'files', 'size'], array_map(
                fn ($r) => [$r['group'], $r['files'], VideoArchiver::human($r['bytes'])],
                $listing->summary()
            ));
            foreach ($listing->warnings as $warning) {
                $this->warn($warning);
            }
            if ($listing->isBroken()) {
                $this->error('Broken: playlists point at segments missing on R2:');
                foreach (array_slice($listing->missing, 0, 20) as $missing) {
                    $this->line("  {$missing}");
                }

                return self::FAILURE;
            }
            $this->info("storage_bucket={$video->storage_bucket} storage_state={$video->storage_state}. Nothing was changed.");

            return self::SUCCESS;
        }

        $video = $service->archive($video);
        $this->info("{$video->id}: storage_state={$video->storage_state}, archive {$video->archive_box}:{$video->archive_path}, "
            .VideoArchiver::human((int) $video->hot_size_bytes));

        return self::SUCCESS;
    }
}
