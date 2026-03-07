<?php

namespace HlsVideos\Services\Qualities\V2;

use HlsVideos\DTOS\VideoConverted;
use HlsVideos\Models\HlsVideoQuality;
use App\Models\HlsVideoQuality as AppHlsVideoQuality;
use FFMpeg;
use FFMpeg\Format\Video\X264;
use HlsVideos\Services\VideoService;
use HlsVideos\Services\CompressService;
use HlsVideos\Models\HlsVideo;
use Illuminate\Support\Facades\Event;
use HlsVideos\Events\VideoConvertedEvent;

class FfmpegLocalStepsEncoderService
{
    protected $quality;
    protected $qualities;
    protected $video;
    protected $headers;


    public function convertVideo($videoFile, HlsVideo $video)
    {
        // try {
        $this->video = $video;
        AppHlsVideoQuality::where('hls_video_id', $this->video->id)->delete();

        foreach (config('hls-videos.qualities') as $configQuality) {
            AppHlsVideoQuality::create([
                'hls_video_id' => $this->video->id,
                'quality' => $configQuality['quality'],
                'convert_service' => $configQuality['convert_service'],
                'status' => AppHlsVideoQuality::CONVERTING,
            ]);
        }

        $this->qualities = AppHlsVideoQuality::where('hls_video_id', $this->video->id)->get();

        $this->downloadVideoFromUploadedVideosDisk();

        if ($this->needsNormalization()) {
            \Log::info("Normalizing video", ['video' => $this->video->id]);
            $this->normalizeVideoFps();
        }

        $transcode = FFMpeg::fromDisk(config('hls-videos.temp_disk'))
            ->open($this->video->temp_video_path)
            ->exportForHLS()
            ->setSegmentLength(10)
            ->setKeyFrameInterval(48);

        if (isset($this->video->stream_data['hls_key'])) {
            $transcode->withEncryptionKey(base64_decode($this->video->stream_data['hls_key']));
        }

        foreach ($this->qualities as $quality) {

            [$width, $height, $videoKbps] = $this->getQualitySettings($quality->quality);
            $format = (new X264('aac'))
                ->setKiloBitrate($videoKbps)
                ->setAudioKiloBitrate(128)
                ->setAdditionalParameters([
                    '-preset', 'ultrafast',
                    '-tune', 'fastdecode',
                    '-profile:v', 'main',
                    '-pix_fmt', 'yuv420p',
                    '-maxrate', $videoKbps.'k',
                    '-bufsize', ($videoKbps * 4).'k',
                    '-threads', '0',
                ]);

            $transcode->addFormat($format, function ($media) use ($width, $height) {
                $media->scale($width, $height);
            });
        }
        // One generator covers all qualities — keyed by kiloBitrate which maps 1-to-1 with quality.
        $kbpsToQuality = $this->qualities->keyBy(function (AppHlsVideoQuality $q) {
            return $this->getQualitySettings($q->quality)[2]; // index 2 = videoKbps
        });

        $videoId = $this->video->id;

        $transcode->useSegmentFilenameGenerator(function ($name, $format, $key, callable $segments, callable $playlist) use ($kbpsToQuality, $videoId) {
            $kbps = $format->getKiloBitrate();
            $quality = $kbpsToQuality->get($kbps);
            $label = $quality ? $quality->quality : $kbps;

            $segments("{$name}-{$label}-{$kbps}-{$key}-%03d.ts");
            $playlist(VideoService::getMediaPath()."{$videoId}/{$label}-vd.m3u8");
        });

        $transcode
            ->toDisk(config('hls-videos.temp_disk'))
            ->save(VideoService::getMediaPath()."{$videoId}/index.m3u8");

        $this->handlePlaylists();

        $this->qualities->map(function (AppHlsVideoQuality $quality) {
            [$width, $height, $videoKbps] = $this->getQualitySettings($quality->quality);
            $bandwidth = $videoKbps * 1024;

            $quality->update([
                'convert_data' => compact('width', 'height', 'videoKbps', 'bandwidth'),
            ]);

            $quality->refresh();
        });

        $this->uploadVideoToStorage();
        CompressService::compressAndUploadVideo($this->video);
        $this->uploadFinished();
        return true;
        // } catch (\Throwable $th) {
        //     throw $th;
        //     \Log::error("FAILED FfmpegService: {$th->getMessage()}");
        //     return new VideoConverted($this->video->qualities()->first());
        // }
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

    protected function handlePlaylists(): void
    {
        $disk = \Storage::disk(config('hls-videos.temp_disk'));
        $mediaPath = VideoService::getMediaPath();
        $videoId = $this->video->id;
        $videoRoot = $mediaPath."{$videoId}/";
        $masterWillRename = [];

        foreach ($this->qualities as $quality) {
            $qualityLabel = $quality->quality;

            if (! is_dir($quality->process_folder_path)) {
                mkdir($quality->process_folder_path, 0755, true);
            }

            $filesInTemp = $disk->files($videoRoot);
            $qualityPrefix = "index-{$qualityLabel}-";
            foreach ($filesInTemp as $filePath) {
                $fileName = basename($filePath);
                if (str_starts_with($fileName, $qualityPrefix)) {
                    // Replace prefix 'index-{quality}' with just 'index-'
                    $newFileName = 'index-'.substr($fileName, strlen("index-{$qualityLabel}-"));
                    $destinationDir = VideoService::getMediaPath()."{$videoId}/{$qualityLabel}/";
                    $destinationPath = $destinationDir.$newFileName;
                    // Move the file to the quality folder with the new name
                    $disk->move($filePath, $destinationPath);
                }
            }

            if ($disk->exists("$videoRoot/{$qualityLabel}-vd.m3u8")) {
                $masterWillRename['old'][] = "{$qualityLabel}-vd.m3u8";
                $masterWillRename['new'][] = route(config('hls-videos.access_route_stream'), [$this->video->id, $qualityLabel, 'vd.m3u8']);
                // Read the original .m3u8 file contents
                $playlistContent = $disk->get("$videoRoot/{$qualityLabel}-vd.m3u8");
                // Replace "index-{$qualityLabel}-" with "index-"
                $newPlaylistContent = str_replace("index-{$qualityLabel}-", "index-", $playlistContent);
                // Save the new playlist content to {quality}/vd.m3u8
                $disk->put("$videoRoot/{$qualityLabel}/vd.m3u8", $newPlaylistContent);
                $disk->delete("$videoRoot/{$qualityLabel}-vd.m3u8");
            }

            $playlistContent = $disk->get("$videoRoot/index.m3u8");
            $newPlaylistContent = str_replace($masterWillRename['old'], $masterWillRename['new'], $playlistContent);
            $disk->put("$videoRoot/index.m3u8", $newPlaylistContent);
        }
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

        // Remove all *.ts files in the temp directory for this video if they exist
        $tempDir = VideoService::getMediaPath()."{$this->video->id}/";
        $localDisk = \Storage::disk(config('hls-videos.temp_disk'));

        if ($localDisk->exists($tempDir)) {
            $allFiles = $localDisk->allFiles($tempDir);
            foreach ($allFiles as $file) {
                if (str_ends_with($file, '.ts')) {
                    $localDisk->delete($file);
                }
            }
        }
    }

    private function uploadVideoToStorage()
    {
        // Remove the original video file from the folder after upload
        $localDisk = \Storage::disk(config('hls-videos.temp_disk'));
        $sourcePath = VideoService::getMediaPath()."{$this->video->id}/{$this->video->file_name}";
        if ($localDisk->exists($sourcePath)) {
            $localDisk->delete($sourcePath);
        }

        foreach (config('hls-videos.storages') as $key => $storage) {
            $service = new $storage['service'];
            $service->uploadAllFolderWithAllFilesToR2($this->video->id, $storage);
        }
    }

    private function uploadFinished()
    {
        $this->video->update(['status' => HlsVideo::READY]);
        $this->video->refresh();
        $this->video->qualities()->update(['status' => HlsVideoQuality::READY]);

        \Storage::disk(config('hls-videos.temp_disk'))->deleteDirectory(VideoService::getMediaPath().$this->video->id);
        \Storage::disk(config('hls-videos.uploaded_videos_disk'))->deleteDirectory("temp-videos/".VideoService::getMediaPath()."{$this->video->id}");
        Event::dispatch(new VideoConvertedEvent($this->video, app('currentTenant')));
    }
    protected function normalizeVideoFps(): void
    {
        $sourcePath = VideoService::getMediaPath()."{$this->video->id}/{$this->video->file_name}";
        $localDisk = \Storage::disk(config('hls-videos.temp_disk'));

        $fullSource = $localDisk->path($sourcePath);
        $tempOutput = $localDisk->path(VideoService::getMediaPath()."{$this->video->id}/temp_normalized.mp4");

        // Normalize to temp file first (can't overwrite input while FFmpeg is reading it)
        $cmd = "/usr/bin/ffmpeg -y -i {$fullSource} -vf fps=30 -vsync cfr -c:v libx264 -preset ultrafast -c:a aac -movflags +faststart {$tempOutput}";
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception("Failed to normalize video: {$sourcePath}");
        }

        // Replace original with normalized
        rename($tempOutput, $fullSource);
    }

    protected function needsNormalization(): bool
    {
        $sourcePath = VideoService::getMediaPath()."{$this->video->id}/{$this->video->file_name}";
        $fullSource = \Storage::disk(config('hls-videos.temp_disk'))->path($sourcePath);

        $cmd = "/usr/bin/ffprobe -v error -select_streams v:0 -show_entries stream=r_frame_rate,avg_frame_rate -of json {$fullSource}";
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return false;
        }

        $data = json_decode(implode('', $output), true);
        $stream = $data['streams'][0] ?? null;

        if (! $stream) {
            return false;
        }

        // Parse r_frame_rate (e.g. "90000/1" → 90000)
        [$rNum, $rDen] = explode('/', $stream['r_frame_rate']);
        $rFps = $rDen > 0 ? $rNum / $rDen : 0;

        // Parse avg_frame_rate (e.g. "250646777/3493015" → 71.75)
        [$aNum, $aDen] = explode('/', $stream['avg_frame_rate']);
        $avgFps = $aDen > 0 ? $aNum / $aDen : 0;

        // Flag as needing normalization if:
        $tooHighFps = $rFps > 60;                              // declared fps is unrealistic
        $vfrDetected = $avgFps > 0 && abs($rFps - $avgFps) / max($avgFps, 1) > 0.2; // r_fps and avg_fps differ by >20%

        return $tooHighFps || $vfrDetected;
    }
}
