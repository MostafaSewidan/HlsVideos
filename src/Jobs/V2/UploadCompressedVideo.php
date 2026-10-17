<?php
namespace HlsVideos\Jobs\V2;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use HlsVideos\Models\HlsVideo;
use Illuminate\Support\Facades\Log;
use HlsVideos\Services\CompressService;
use HlsVideos\Services\VideoService;

class UploadCompressedVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected HlsVideo $video, protected $tenant)
    {
    }

    public function handle(): void
    {
        try {
            $this->tenant->makeCurrent();
            CompressService::compressAndUploadVideo($this->video, $this->tenant);

            VideoService::deleteLocalWorkingFiles($this->video);
            VideoService::deleteRemoteOriginal($this->video);
        } catch (\Throwable $e) {
            Log::error('UploadCompressedVideo failed', [
                'video_id' => $this->video->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * The video is already READY; without this a failed zip leaves the HLS output
     * on the encoder's disk and the original in temp-videos/ for good.
     */
    public function failed(\Throwable $e): void
    {
        $this->tenant->makeCurrent();
        VideoService::deleteLocalWorkingFiles($this->video);
        //in the biggning i need to keep the original , if we have problem i can solve it and try again with it 
        // VideoService::deleteRemoteOriginal($this->video);
    }
}
