<?php

namespace HlsVideos\Console;

use HlsVideos\Models\HlsVideo;
use HlsVideos\Services\VideoCleanup;
use HlsVideos\Services\VideoUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time cleanup of videos left behind by deleted lessons: every lesson the
 * video was linked to is gone (base_backend docs/video-archive-plan.md, decision
 * 22). Library folders don't save them.
 *
 * Without --force it only reports, and writes the list to a CSV. With --force the
 * videos are deleted for good (files and row), and the dead hls_videoables rows of
 * videos still used elsewhere are removed.
 *
 * Works on the current tenant: `tenants:artisan "hls-videos:purge-dead" --tenant=..`.
 */
class PurgeDeadVideos extends Command
{
    protected $signature = 'hls-videos:purge-dead
        {--force : delete them; without it, only report}
        {--csv= : where to write the list (default storage/app/purge-dead-{media_folder}.csv)}';

    protected $description = 'Report, or with --force delete, videos whose lessons are all gone';

    public function handle(VideoCleanup $cleanup): int
    {
        $dead = $this->deadVideos();
        $staleRows = VideoUsage::deadRows()->whereIn('v.hls_video_id', VideoUsage::liveRows()->select('v.hls_video_id'))->count();

        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        $name = $tenant->media_folder ?? 'tenant';
        $seconds = $dead->sum(fn (HlsVideo $v) => (float) ($v->stream_data['duration'] ?? 0));

        $this->line("Tenant {$name}:");
        $this->line("  videos whose lessons are all gone: {$dead->count()} (".round($seconds / 3600, 1).' hours of video)');
        $this->line("  dead lesson links on videos still used elsewhere: {$staleRows}");

        $csv = $this->option('csv') ?: storage_path("app/purge-dead-{$name}.csv");
        $this->writeCsv($csv, $dead);
        $this->line("  list: {$csv}");

        if (! $this->option('force')) {
            $this->warn('Nothing deleted. Run again with --force to delete them.');

            return self::SUCCESS;
        }

        foreach ($dead as $video) {
            DB::transaction(fn () => $cleanup->deleteNow($video));
        }
        $removed = 0;
        foreach (VideoUsage::deadRows()->pluck('v.id')->chunk(500) as $ids) {
            $removed += DB::table('hls_videoables')->whereIn('id', $ids->all())->delete();
        }

        $this->info("Queued {$dead->count()} video(s) for deletion, removed {$removed} dead lesson link(s).");

        return self::SUCCESS;
    }

    /** Videos with lesson links, none of them live. Already trashed ones included. */
    private function deadVideos()
    {
        return HlsVideo::withTrashed()
            ->whereIn('id', VideoUsage::deadRows()->select('v.hls_video_id'))
            ->whereNotIn('id', VideoUsage::liveRows()->select('v.hls_video_id'))
            ->orderBy('created_at')
            ->get();
    }

    private function writeCsv(string $path, $videos): void
    {
        @mkdir(dirname($path), 0755, true);
        $out = fopen($path, 'w');
        fputcsv($out, ['id', 'original_file_name', 'status', 'duration_seconds', 'created_at', 'deleted_at']);
        foreach ($videos as $video) {
            fputcsv($out, [
                $video->id,
                $video->original_file_name,
                $video->status,
                $video->stream_data['duration'] ?? '',
                optional($video->created_at)->toDateTimeString(),
                optional($video->deleted_at)->toDateTimeString(),
            ]);
        }
        fclose($out);
    }
}
