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

            $this->downloadVideoFromUploadedVideosDisk();

            [$width, $height, $videoKbps] = $this->getQualitySettings($this->quality->quality);
            $bandwidth = $videoKbps * 1024;

            // Create format
            $format = (new X264('aac'))
                ->setKiloBitrate($videoKbps)
                ->setAudioKiloBitrate(128);

            FFMpeg::fromDisk(config('hls-videos.temp_disk'))
                ->open($this->video->temp_video_path)
                ->exportForHLS()
                ->withEncryptionKey(base64_decode($this->video->stream_data['hls_key']))
                ->setSegmentLength(4) // seconds
                ->setKeyFrameInterval(96)
                ->addFormat($format, function ($media) use ($width, $height) {
                    $media->scale($width, $height);
                })->beforeSaving(function ($commands) use ($videoKbps) {
                    return array_merge($commands, [
                        '-preset', 'veryfast',
                        '-profile:v', 'main',
                        '-pix_fmt', 'yuv420p',
                        '-crf', '23',

                        // HARD BITRATE LIMIT
                        '-maxrate', $videoKbps.'k',
                        '-bufsize', ($videoKbps * 2).'k',

                        // HLS GOP control
                        '-g', '96',
                        '-keyint_min', '96',
                        '-sc_threshold', '0',

                        '-movflags', '+faststart',
                    ]);
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
        return match ($quality) {
            '1080' => [1920, 1080, 3000],
            '720' => [1280, 720, 1500],
            '480' => [854, 480, 500],
            '360' => [640, 360, 400],
            default => [1280, 720, 1000],
        };
    }

    protected function downloadVideoFromUploadedVideosDisk()
    {
        $sourcePath = VideoService::getMediaPath()."{$this->video->id}/{$this->video->file_name}";
        $localDisk = \Storage::disk(config('hls-videos.temp_disk'));
        if (! $localDisk->exists($sourcePath)) {
            $uploadedVideosPath = "temp-videos/".VideoService::getMediaPath()."{$this->video->id}/{$this->video->file_name}";
            $uploadedVideosDisk = \Storage::disk(config('hls-videos.uploaded_videos_disk'));

            if ($uploadedVideosDisk->exists($uploadedVideosPath)) {
                $stream = $uploadedVideosDisk->readStream($uploadedVideosPath);

                if ($stream === false) {
                    throw new \Exception("Failed to read stream from R2: {$sourcePath}");
                }

                $localDisk->put($sourcePath, $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }

                $uploadedVideosDisk->deleteDirectory("temp-videos/".VideoService::getMediaPath()."{$this->video->id}");
            } else {
                throw new \Exception("Source video file does not exist on uploaded_videos_disk: {$sourcePath}");
            }
        }
    }
}
