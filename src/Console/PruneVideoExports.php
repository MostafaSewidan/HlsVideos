<?php

namespace HlsVideos\Console;

use HlsVideos\Repositories\HlsVideoExportRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the files of expired MP4 exports and marks their rows expired.
 * Works on the current tenant's database: schedule it through
 * `tenants:artisan "hls-videos:prune-exports"`.
 */
class PruneVideoExports extends Command
{
    protected $signature = 'hls-videos:prune-exports';

    protected $description = 'Delete expired MP4 exports of HLS videos';

    public function handle(HlsVideoExportRepository $exports): int
    {
        $count = 0;

        foreach ($exports->expired() as $export) {
            try {
                if ($export->path) {
                    Storage::disk($export->disk)->delete($export->path);
                }

                $exports->markExpired($export);
                $count++;
            } catch (\Throwable $e) {
                $this->error("Export {$export->id}: {$e->getMessage()}");
            }
        }

        $this->info("Pruned {$count} video export(s).");

        return self::SUCCESS;
    }
}
