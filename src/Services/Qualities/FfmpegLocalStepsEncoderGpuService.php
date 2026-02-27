<?php

namespace HlsVideos\Services\Qualities;

use HlsVideos\DTOS\VideoConverted;
use HlsVideos\Models\HlsVideoQuality;
use HlsVideos\Services\Contracts\VideoQualityProcessorInterface;
use FFMpeg;
use HlsVideos\Services\VideoService;
use FFMpeg\Format\Video\X264;
use HlsVideos\Services\Formats\H264Nvenc;

class FfmpegLocalStepsEncoderGpuService implements VideoQualityProcessorInterface
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

            $format = (new X264('aac'))
                ->setKiloBitrate($videoKbps)
                ->setAudioKiloBitrate(128);

            $transcode = FFMpeg::fromDisk(config('hls-videos.temp_disk'))
                ->open($this->video->temp_video_path)
                ->exportForHLS();

            if (isset($this->video->stream_data['hls_key'])) {
                $transcode->withEncryptionKey(base64_decode($this->video->stream_data['hls_key']));
            }

            $transcode
                ->setSegmentLength(4)       // seconds per .ts segment
                ->setKeyFrameInterval(96)
                ->addFormat($format)        // no scale() here — handled via scale_cuda below
                ->beforeSaving(function ($commands) use ($videoKbps, $width, $height) {
                    \Log::info('FFmpeg raw commands', ['commands' => $commands]);
                    dd($commands);

                    // Strip CPU-only flags
                    $strip = ['-crf', '-preset', '-profile:v', '-vf', '-filter:v', '-pix_fmt', '-b:v'];
                    $filtered = [];
                    $skipNext = false;

                    foreach ($commands as $cmd) {
                        if ($skipNext) {
                            $skipNext = false;
                            continue;
                        }
                        if (in_array($cmd, $strip)) {
                            $skipNext = true;
                            continue;
                        }
                        $filtered[] = $cmd;
                    }

                    // Replace libx264 with h264_nvenc
                    $filtered = array_map(fn ($cmd) => $cmd === 'libx264' ? 'h264_nvenc' : $cmd, $filtered);

                    return array_merge($filtered, [
                        '-hwaccel', 'cuda',
                        '-hwaccel_output_format', 'cuda',
                        '-vf', "scale_cuda={$width}:{$height}",
                        '-b:v', $videoKbps.'k',
                        '-preset', 'p4',
                        '-tune', 'hq',
                        '-rc', 'vbr',
                        '-profile:v', 'main',
                        '-pix_fmt', 'yuv420p',
                        '-maxrate', $videoKbps.'k',
                        '-bufsize', ($videoKbps * 2).'k',
                        '-g', '96',
                        '-keyint_min', '96',
                        '-sc_threshold', '0',
                        '-movflags', '+faststart',
                    ]);
                })
                ->useSegmentFilenameGenerator(function ($name, $format, $key, callable $segments, callable $playlist) {
                    $segments("{$name}-{$format->getKiloBitrate()}-{$key}-%03d.ts");
                    $playlist(VideoService::getMediaPath()."{$this->video->id}/{$this->quality->quality}/vd.m3u8");
                })
                ->toDisk(config('hls-videos.temp_disk'))
                ->save(VideoService::getMediaPath()."{$this->video->id}/{$this->quality->quality}/index.m3u8");

            $quality->update([
                'convert_data' => compact('width', 'height', 'videoKbps', 'bandwidth')
            ]);

            $quality->refresh();

            $uploadedVideosDisk = \Storage::disk(config('hls-videos.uploaded_videos_disk'));
            $uploadedVideosPath = "temp-videos/".VideoService::getMediaPath()."{$this->video->id}";
            if ($uploadedVideosDisk->exists($uploadedVideosPath)) {
                // $uploadedVideosDisk->deleteDirectory($uploadedVideosPath);
            }

            return new VideoConverted($quality);
        } catch (\Throwable $th) {
            throw $th;
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

                // $uploadedVideosDisk->deleteDirectory("temp-videos/".VideoService::getMediaPath()."{$this->video->id}");
            } else {
                throw new \Exception("Source video file does not exist on uploaded_videos_disk: {$sourcePath}");
            }
        }
    }
}
