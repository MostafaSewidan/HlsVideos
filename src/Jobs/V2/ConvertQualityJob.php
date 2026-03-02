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