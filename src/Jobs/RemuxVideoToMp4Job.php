<?php

namespace HlsVideos\Jobs;

use HlsVideos\Repositories\HlsVideoExportRepository;
use HlsVideos\Services\Mp4ExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Builds the MP4 of one hls_video_exports row (see Mp4ExportService::export).
 * Runs on its own queue (exports.queue) so it never waits behind, or holds up,
 * the encoding of new uploads.
 */
class RemuxVideoToMp4Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout;

    public function __construct(public int $exportId, protected $tenant)
    {
        $this->timeout = (int) config('hls-videos.exports.timeout', 3600);
    }

    public function handle(): void
    {
        $this->tenant->makeCurrent();

        $export = (new HlsVideoExportRepository)->find($this->exportId);

        if (! $export) {
            return;
        }

        (new Mp4ExportService)->export($export);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RemuxVideoToMp4Job failed', [
            'export_id' => $this->exportId,
            'message' => $e->getMessage(),
        ]);

        $this->tenant->makeCurrent();

        $repository = new HlsVideoExportRepository;

        if ($export = $repository->find($this->exportId)) {
            $repository->markFailed($export, $e->getMessage());
        }
    }
}
