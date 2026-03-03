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
use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Event;
use HlsVideos\Events\VideoConvertedEvent;
use HlsVideos\Events\VideoConvertedErrorEvent;

class ConvertQualityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;       // 1 hour (encoding can be long)
    public int $tries = 1;            // don't retry — encoding from scratch wastes time
    public int $maxExceptions = 1;

    public function __construct(protected HlsVideo $video, protected $tenant)
    {
    }

    public function handle(): void
    {
        try {
            $this->tenant->makeCurrent();

            $service = new FfmpegLocalStepsEncoderService;
            $service->convertVideo('', $this->video);
        } catch (\Throwable $e) {
            Event::dispatch(new VideoConvertedErrorEvent($this->video, app('currentTenant'), $e->getMessage()));
            Log::error('ConvertQualityJob failed', [
                'video_id' => $this->video->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Event::dispatch(new VideoConvertedErrorEvent($this->video, app('currentTenant'), $e->getMessage()));

        Log::error('ConvertQualityJob permanently failed after retries', [
            'video_id' => $this->video->id,
            'message' => $e->getMessage(),
        ]);
        // Optionally: update $this->video status, notify, etc.
    }
}