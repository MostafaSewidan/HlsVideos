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
use HlsVideos\Services\VideoService;
use HlsVideos\Services\EncodeProgress;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Event;
use HlsVideos\Events\VideoConvertedErrorEvent;

class ConvertQualityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // public int $timeout = 3600;       // 1 hour (encoding can be long)
    // public int $tries = 1;            // don't retry — encoding from scratch wastes time
    // public int $maxExceptions = 1;

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
            Log::error('ConvertQualityJob failed', [
                'video_id' => $this->video->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // failed() reports it; reporting here too sent the master "failed" twice.
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ConvertQualityJob permanently failed after retries', [
            'video_id' => $this->video->id,
            'message' => $e->getMessage(),
            'progress' => EncodeProgress::describe($this->video->id),
        ]);
        $this->tenant->makeCurrent();

        // The source is re-downloaded on retry; the R2 original stays for it.
        try {
            VideoService::deleteLocalWorkingFiles($this->video);
        } catch (\Throwable $cleanupError) {
            Log::warning('ConvertQualityJob cleanup failed', ['video_id' => $this->video->id, 'message' => $cleanupError->getMessage()]);
        }

        // The node is destroyed after a failure, so this message (failed_reason on
        // the master) is all that is left: it says which step the encode stopped at.
        $progress = EncodeProgress::describe($this->video->id);
        EncodeProgress::forget($this->video->id);

        $message = $e instanceof MaxAttemptsExceededException
            ? 'Worker timeout: the job ran longer than the worker allows. '.$e->getMessage()
            : get_class($e).': '.$e->getMessage();

        if ($progress) {
            $message = "[{$progress}] {$message}";
        }

        Event::dispatch(new VideoConvertedErrorEvent($this->video, app('currentTenant'), $message));
    }
}