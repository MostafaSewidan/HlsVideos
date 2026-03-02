<?php
namespace HlsVideos\Jobs\V2;

use HlsVideos\DTOS\VideoConverted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use HlsVideos\Services\Qualities\V2\FfmpegLocalStepsEncoderService;
use HlsVideos\Models\HlsVideo;

class ConvertQualityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // public int $timeout = 100000; // 10 seconds
    // public int $tries = 1;      // don't retry — encoding from scratch wastes time
    // public int $maxExceptions = 1;

    public function __construct(protected HlsVideo $video, protected $tenant)
    {
    }

    public function handle()
    {
        // try {

        $this->tenant->makeCurrent();

        $service = new FfmpegLocalStepsEncoderService;
        $service->convertVideo('', $this->video);
        // } catch (\Throwable $e) {
        //     \Log::error("FAILED ConvertQualityJob: {$e->getMessage()}");
        //     throw $e;
        // }
    }
}