<?php

namespace HlsVideos\Services\Qualities;

use HlsVideos\DTOS\VideoConverted;
use HlsVideos\Models\HlsVideoQuality;
use HlsVideos\Services\Contracts\VideoQualityProcessorInterface;
use FFMpeg;
use FFMpeg\Format\Video\X264;
use HlsVideos\Services\VideoService;

class FfmpegService implements VideoQualityProcessorInterface
{
    protected $quality;
    protected $video;
    protected $headers;


    public function convertVideo($videoFile, HlsVideoQuality $quality): VideoConverted
    {
        try {
            $this->video = $quality->video;
            $this->quality = $quality;
            [$width, $height, $videoKbps] = $this->getQualitySettings($this->quality->quality);
            $bandwidth = $videoKbps * 1024;

            // Create format
            $format = (new X264)->setKiloBitrate($videoKbps);

            FFMpeg::fromDisk(config('hls-videos.temp_disk'))
                ->open($this->video->temp_video_path)
                ->exportForHLS()
                ->withEncryptionKey(base64_decode($this->video->stream_data['hls_key']))
                ->setSegmentLength(4) // seconds
                ->setKeyFrameInterval(48) // for better seeking performance
                ->addFormat($format, function ($media) use ($width, $height) {
                    $media->scale($width, $height);
                })
                ->useSegmentFilenameGenerator(function ($name, $format, $key, callable $segments, callable $playlist) {
                    $segments("{$name}-{$format->getKiloBitrate()}-{$key}-%03d.ts");
                    $playlist((VideoService::getMediaPath()."{$this->video->id}/{$this->quality->quality}/vd.m3u8"));
                })
                ->toDisk(config('hls-videos.temp_disk')) // Output disk (can be S3, local, etc.)
                ->save(VideoService::getMediaPath()."{$this->video->id}/{$this->quality->quality}/index.m3u8");

            $quality->update([
                'convert_data' => compact('width', 'height', 'videoKbps', 'bandwidth')
            ]);

            $quality->refresh();

            return new VideoConverted($quality);
        } catch (\Throwable $th) {
            \Log::error("FAILED FfmpegService: {$th->getMessage()}");
            return new VideoConverted($quality);
        }
    }

    protected function getQualitySettings($quality)
    {
        // Width, Height, Video Bitrate in Kbps
        return match ($quality) {
            '1080' => [1920, 1080, 3000],
            '720' => [1280, 720, 1500],
            '480' => [854, 480, 500],
            '360' => [640, 360, 400],
            default => [1280, 720, 1000],
        };
    }
}
