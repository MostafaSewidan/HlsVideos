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

            \Storage::disk(config('hls-videos.temp_disk'))->deleteDirectory(VideoService::getMediaPath().$this->video->id);
            \Storage::disk(config('hls-videos.uploaded_videos_disk'))->deleteDirectory("temp-videos/".VideoService::getMediaPath()."{$this->video->id}");
            } catch (\Throwable $e) {
            Log::error('UploadCompressedVideo failed', [
                'video_id' => $this->video->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}