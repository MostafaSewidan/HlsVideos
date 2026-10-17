<?php

namespace HlsVideos\Services\Qualities\V2;

use HlsVideos\Services\CompressService;

use HlsVideos\DTOS\VideoConverted;
use HlsVideos\Models\HlsVideoQuality;
use App\Models\HlsVideoQuality as AppHlsVideoQuality;
use FFMpeg;
use FFMpeg\Format\Video\X264;
use HlsVideos\Services\VideoService;
use HlsVideos\Models\HlsVideo;
use Illuminate\Support\Facades\Event;
use HlsVideos\Events\VideoConvertedEvent;
use HlsVideos\Jobs\V2\UploadCompressedVideo;

class FfmpegLocalStepsEncoderService
{
    protected $quality;
    protected $qualities;
    protected $video;
    protected $headers;
    protected ?array $sourceDimensions = null;
    protected array $qualityTargets = [];
    protected ?array $sourceFps = null;

    /** Frame rate every normalized video is encoded at. */
    protected const NORMALIZED_FPS = 30;


    public function convertVideo($videoFile, HlsVideo $video)
    {
        $videoSerivce = new VideoService();
        $this->video = $video;
        AppHlsVideoQuality::where('hls_video_id', $this->video->id)->delete();

        // The source is needed before the quality rows: their labels depend on its size.
        $this->downloadVideoFromUploadedVideosDisk();

        // Normalizing used to be a full separate encode before the HLS encode.
        // With inline normalization on, the HLS encode itself outputs constant
        // NORMALIZED_FPS instead, so the video is encoded once.
        $needsFpsNormalization = $this->needsNormalization();
        $inlineFpsNormalization = $needsFpsNormalization && config('hls-videos.inline_fps_normalization', true);

        if ($needsFpsNormalization) {
            \Log::info('Normalizing video fps', [
                'video' => $this->video->id,
                'mode' => $inlineFpsNormalization ? 'inline' : 'separate-pass',
                'source_fps' => $this->sourceFps,
            ]);

            if (! $inlineFpsNormalization) {
                $this->normalizeVideoFps();
            }
        }

        foreach ($this->resolveQualityTargets() as $target) {
            AppHlsVideoQuality::create([
                'hls_video_id' => $this->video->id,
                'quality' => $target['quality'],
                'convert_service' => $target['convert_service'],
                'status' => AppHlsVideoQuality::CONVERTING,
            ]);
        }

        $this->qualities = AppHlsVideoQuality::where('hls_video_id', $this->video->id)->get();

        $this->saveVideoOrientationType();



        $videoSerivce->createThumb($this->video);
        $videoSerivce->getVideoDuration($this->video);
        $this->video->refresh();
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
            $params = [
                '-preset', 'ultrafast',
                '-tune', 'fastdecode',
                '-profile:v', 'main',
                '-pix_fmt', 'yuv420p',
                '-maxrate', $videoKbps.'k',
                '-bufsize', ($videoKbps * 4).'k',
                '-threads', '0',
            ];

            if ($inlineFpsNormalization) {
                // Constant output frame rate: frames are duplicated/dropped by timestamp,
                // which is what the old `-vf fps=30 -vsync cfr` pass did. Needs ffmpeg >= 5.1.
                array_push($params, '-r', (string) self::NORMALIZED_FPS, '-fps_mode', 'cfr');
            }

            $format = (new X264('aac'))
                ->setKiloBitrate($videoKbps)
                ->setAudioKiloBitrate(128)
                ->setAdditionalParameters($params);

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

        $encodeStartedAt = microtime(true);
        $transcode
            ->toDisk(config('hls-videos.temp_disk'))
            ->save(VideoService::getMediaPath()."{$videoId}/index.m3u8");

        \Log::info('HLS encode finished', [
            'video' => $videoId,
            'seconds' => round(microtime(true) - $encodeStartedAt, 1),
            'duration' => $this->video->stream_data['duration'] ?? null,
            'fps_normalization' => $needsFpsNormalization ? ($inlineFpsNormalization ? 'inline' : 'separate-pass') : 'none',
            'source_fps' => $this->sourceFps,
        ]);

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

        if (CompressService::shouldCompress(app('currentTenant'))) {
            UploadCompressedVideo::dispatch($this->video, app('currentTenant'))->onQueue('compress');
        } else {
            VideoService::deleteLocalWorkingFiles($this->video);
            VideoService::deleteRemoteOriginal($this->video);
        }

        $this->uploadFinished();
        return true;
    }

    protected function getSourceDimensions(): array
    {
        if ($this->sourceDimensions !== null) {
            return $this->sourceDimensions;
        }

        $sourcePath = VideoService::getMediaPath()."{$this->video->id}/{$this->video->file_name}";
        $fullSource = \Storage::disk(config('hls-videos.temp_disk'))->path($sourcePath);

        $cmd = '/usr/bin/ffprobe -v error -select_streams v:0 -show_entries stream=width,height:stream_side_data=rotation -of json '.escapeshellarg($fullSource);
        exec($cmd, $output, $returnCode);

        $width = 1280;
        $height = 720;
        if ($returnCode === 0) {
            $data = json_decode(implode('', $output), true);
            $stream = $data['streams'][0] ?? null;
            if ($stream) {
                $width = (int) ($stream['width'] ?? $width);
                $height = (int) ($stream['height'] ?? $height);
                $rotation = 0;
                foreach (($stream['side_data_list'] ?? []) as $sd) {
                    if (isset($sd['rotation'])) {
                        $rotation = (int) $sd['rotation'];
                        break;
                    }
                }
                if (abs($rotation) === 90 || abs($rotation) === 270) {
                    [$width, $height] = [$height, $width];
                }
            }
        }

        return $this->sourceDimensions = [$width, $height];
    }

    protected function saveVideoOrientationType(): void
    {
        [$srcW, $srcH] = $this->getSourceDimensions();
        $type = $srcH > $srcW ? HlsVideo::ORIENTATION_PORTRAIT : HlsVideo::ORIENTATION_LANDSCAPE;

        $this->video->update([
            'stream_data' => [
                'type' => $type,
                ...($this->video->stream_data ?? []),
            ],
        ]);
        $this->video->refresh();
    }

    protected function getTierSettings($quality): array
    {
        return match ((string) $quality) {
            '1080' => [1080, 3000],
            '720' => [720, 1500],
            '480' => [480, 500],
            '360' => [360, 400],
            default => [720, 1000],
        };
    }

    /**
     * Builds the quality ladder from the configured tiers, fitted to the source.
     * A tier above the source is replaced by the source's own height, so a 640p
     * upload gives 640/480/360 and a 1080p one gives 720/480/360. Tiers that
     * collapse onto the same height are encoded once, at the lowest tier's bitrate.
     */
    protected function resolveQualityTargets(): array
    {
        [$srcW, $srcH] = $this->getSourceDimensions();
        $srcShort = min($srcW, $srcH);
        $srcShort -= $srcShort % 2;

        $tiers = collect(config('hls-videos.qualities'))
            ->map(function ($configQuality) {
                [$shortEdge, $videoKbps] = $this->getTierSettings($configQuality['quality']);

                return $configQuality + ['short_edge' => $shortEdge, 'kbps' => $videoKbps];
            })
            ->sortBy('short_edge');

        $targets = [];
        foreach ($tiers as $tier) {
            $shortEdge = min($tier['short_edge'], $srcShort);
            $label = (string) $shortEdge;

            if (isset($targets[$label])) {
                continue;
            }

            $targets[$label] = [
                'quality' => $label,
                'convert_service' => $tier['convert_service'],
                'short_edge' => $shortEdge,
                'kbps' => $tier['kbps'],
            ];
        }

        krsort($targets, SORT_NUMERIC);

        return $this->qualityTargets = $targets;
    }

    protected function getQualitySettings($quality)
    {
        if (isset($this->qualityTargets[(string) $quality])) {
            ['short_edge' => $shortEdge, 'kbps' => $videoKbps] = $this->qualityTargets[(string) $quality];
        } else {
            [$shortEdge, $videoKbps] = $this->getTierSettings($quality);
        }

        [$srcW, $srcH] = $this->getSourceDimensions();
        $isPortrait = $srcH > $srcW;
        $srcShort = min($srcW, $srcH);
        $srcLong = max($srcW, $srcH);

        // Clamp to source - never upscale
        $targetShort = min($shortEdge, $srcShort);
        $targetLong = (int) round($targetShort * ($srcLong / max($srcShort, 1)));

        // H.264 yuv420p requires even dimensions
        $targetShort -= $targetShort % 2;
        $targetLong -= $targetLong % 2;

        [$width, $height] = $isPortrait
            ? [$targetShort, $targetLong]
            : [$targetLong, $targetShort];

        return [$width, $height, $videoKbps];
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
        Event::dispatch(new VideoConvertedEvent($this->video, app('currentTenant')));
    }
    protected function normalizeVideoFps(): void
    {
        $sourcePath = VideoService::getMediaPath()."{$this->video->id}/{$this->video->file_name}";
        $localDisk = \Storage::disk(config('hls-videos.temp_disk'));

        $fullSource = $localDisk->path($sourcePath);
        $tempOutput = $localDisk->path(VideoService::getMediaPath()."{$this->video->id}/temp_normalized.mp4");

        // Normalize to temp file first (can't overwrite input while FFmpeg is reading it)
        $cmd = '/usr/bin/ffmpeg -y -i '.escapeshellarg($fullSource).' -vf fps='.self::NORMALIZED_FPS.' -fps_mode cfr -c:v libx264 -preset ultrafast -c:a aac -movflags +faststart '.escapeshellarg($tempOutput);
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

        $cmd = '/usr/bin/ffprobe -v error -select_streams v:0 -show_entries stream=r_frame_rate,avg_frame_rate -of json '.escapeshellarg($fullSource);
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            return false;
        }

        $data = json_decode(implode('', $output), true);
        $stream = $data['streams'][0] ?? null;

        if (! $stream) {
            return false;
        }

        // e.g. "90000/1" -> 90000, "250646777/3493015" -> 71.75
        $rFps = $this->parseFrameRate($stream['r_frame_rate'] ?? null);
        $avgFps = $this->parseFrameRate($stream['avg_frame_rate'] ?? null);
        $this->sourceFps = ['r' => round($rFps, 3), 'avg' => round($avgFps, 3)];

        // Anything above this is brought down to NORMALIZED_FPS. 60 keeps 60fps
        // sources as they are; 30 also halves the encode work for 60fps recordings.
        $maxFps = (float) config('hls-videos.max_fps', 60);

        $tooHighFps = $rFps > $maxFps + 0.5;   // declared fps is unrealistic or above the cap (+0.5 tolerates 59.94/29.97)
        $vfrDetected = $avgFps > 0 && abs($rFps - $avgFps) / max($avgFps, 1) > 0.2; // r_fps and avg_fps differ by >20%

        return $tooHighFps || $vfrDetected;
    }

    private function parseFrameRate(?string $rate): float
    {
        if (! $rate) {
            return 0.0;
        }

        $parts = explode('/', $rate, 2);
        $num = (float) $parts[0];
        $den = isset($parts[1]) ? (float) $parts[1] : 1.0;

        return $den > 0 ? $num / $den : 0.0;
    }
}
