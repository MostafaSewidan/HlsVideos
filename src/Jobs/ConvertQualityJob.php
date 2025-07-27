<?php
namespace  HlsVideos\Jobs;

use HlsVideos\DTOS\VideoConverted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use  HlsVideos\Factories\VideoQualityProcessorFactory;
use  HlsVideos\Models\HlsVideo;
use  HlsVideos\Models\HlsVideoQuality;


class ConvertQualityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;


    public function __construct(protected HlsVideoQuality $hlsVideoQuality)
    {
    }

    public function handle()
    {
        try {
            $quality = $this->hlsVideoQuality->quality;
            $video = $this->hlsVideoQuality->video;

            if($video->status != HlsVideo::READY)
                $video->update(['status' => HlsVideo::PROCESSING]);

            $service = VideoQualityProcessorFactory::make($quality);

            switch($this->hlsVideoQuality->status){
                case HlsVideoQuality::UPLOADING:
                    new VideoConverted($this->hlsVideoQuality);
                    break;
                case HlsVideoQuality::READY:
                    break;
                default:
                    $this->hlsVideoQuality->updateStatusTo(HlsVideoQuality::CONVERTING);
                    $service->convertVideo($video->temp_video,$this->hlsVideoQuality);
                    break;
            }
        } catch (\Throwable $e) {
            \Log::error("FAILED ConvertQualityJob: {$e->getMessage()}");
            throw $e;
        }
    }
}
