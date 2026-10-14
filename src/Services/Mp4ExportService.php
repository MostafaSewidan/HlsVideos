<?php

namespace HlsVideos\Services;

use HlsVideos\Jobs\RemuxVideoToMp4Job;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Models\HlsVideoExport;
use HlsVideos\Repositories\HlsVideoExportRepository;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Turns one HLS quality of a video into a single MP4 the dashboard can download.
 *
 * This is a remux, not a transcode: the segments already hold H.264 + AAC, so
 * ffmpeg only rewraps them (`-c copy`). The cost is the download of the
 * segments and the upload of the result, not CPU.
 *
 * The result is cached per (video, quality, disk) in hls_video_exports and
 * reused until it expires (exports.ttl_days); `hls-videos:prune-exports`
 * deletes expired files.
 */
class Mp4ExportService
{
    protected HlsVideoExportRepository $exports;

    public function __construct(?HlsVideoExportRepository $exports = null)
    {
        $this->exports = $exports ?? new HlsVideoExportRepository;
    }

    public static function disk(): string
    {
        return config('hls-videos.exports.disk') ?: config('hls-videos.stream_disk');
    }

    /**
     * Returns the export of $quality, dispatching the remux job when there is
     * no usable file yet. Safe to call repeatedly: a running export is left
     * alone and a ready one only has its expiry pushed back.
     */
    public function request(HlsVideo $video, string $quality, ?string $disk = null): HlsVideoExport
    {
        $export = $this->exports->firstOrCreate($video, $quality, $disk ?? self::disk());

        if ($export->status === HlsVideoExport::READY && $export->path) {
            $this->exports->extend($export, $this->expiresAt());

            return $export;
        }

        if ($export->wasRecentlyCreated || $this->exports->claimForDispatch($export)) {
            RemuxVideoToMp4Job::dispatch($export->id, app('currentTenant'))
                ->onQueue(config('hls-videos.exports.queue', 'hls-video-exports'));
        }

        return $export->refresh();
    }

    /**
     * The job's body. Throws on failure; the job's failed() records it.
     */
    public function export(HlsVideoExport $export): void
    {
        $this->exports->markProcessing($export);

        $video = $export->video;
        $quality = $export->quality;
        $tempDisk = Storage::disk(config('hls-videos.temp_disk'));
        $workDir = VideoService::getMediaPath()."{$video->id}/export-{$export->id}";

        $tempDisk->deleteDirectory($workDir);
        $tempDisk->makeDirectory($workDir);

        try {
            $playlist = $this->downloadQuality($video, $quality, $tempDisk, $workDir);
            $tempDisk->put("$workDir/local.m3u8", $playlist);

            $this->remux($tempDisk->path($workDir));

            $path = VideoService::getMediaPath()."{$video->id}/exports/".Str::random(40)."/{$quality}.mp4";
            $this->upload($tempDisk->path("$workDir/out.mp4"), $export->disk, $path);

            $previousPath = $export->path;
            $this->exports->markReady($export, $path, filesize($tempDisk->path("$workDir/out.mp4")), $this->expiresAt());

            if ($previousPath && $previousPath !== $path) {
                Storage::disk($export->disk)->delete($previousPath);
            }
        } finally {
            $tempDisk->deleteDirectory($workDir);
        }
    }

    /**
     * Copies the quality's segments into $workDir and returns its playlist
     * rewritten to point at them, with the encryption key as a local file.
     *
     * Playlists on the stream disk name segments either by file name or by an
     * old absolute stream URL, and the key by "secret.key" or a full route
     * URL (see VideoService::getStreamFileContent); all of them reduce to a
     * basename in the same folder.
     */
    protected function downloadQuality(HlsVideo $video, string $quality, $tempDisk, string $workDir): string
    {
        $streamDisk = Storage::disk(config('hls-videos.stream_disk'));
        $remoteDir = VideoService::getMediaPath()."{$video->id}/{$quality}";
        $playlist = $streamDisk->get("$remoteDir/vd.m3u8");

        if (! $playlist) {
            throw new \RuntimeException("Playlist not found: {$remoteDir}/vd.m3u8");
        }

        $lines = [];
        $hasKey = false;

        foreach (preg_split('/\r\n|\r|\n/', trim($playlist)) as $line) {
            $line = trim($line);

            if (str_starts_with($line, '#EXT-X-KEY')) {
                $hasKey = $hasKey || ! str_contains($line, 'METHOD=NONE');
                $line = preg_replace('/URI="[^"]*"/', 'URI="secret.key"', $line);
            } elseif ($line !== '' && ! str_starts_with($line, '#')) {
                $name = basename(parse_url($line, PHP_URL_PATH) ?? '');

                if (! preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
                    throw new \RuntimeException("Unexpected segment name in {$remoteDir}/vd.m3u8: {$line}");
                }

                $stream = $streamDisk->readStream("$remoteDir/$name");

                if (! $stream) {
                    throw new \RuntimeException("Segment not found: {$remoteDir}/{$name}");
                }

                $tempDisk->writeStream("$workDir/$name", $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }

                $line = $name;
            }

            $lines[] = $line;
        }

        if ($hasKey) {
            if (empty($video->stream_data['hls_key'])) {
                throw new \RuntimeException("Video {$video->id} is encrypted but has no hls_key");
            }

            $tempDisk->put("$workDir/secret.key", base64_decode($video->stream_data['hls_key']));
        }

        return implode("\n", $lines)."\n";
    }

    protected function remux(string $dir): void
    {
        $process = new Process([
            config('laravel-ffmpeg.ffmpeg.binaries', 'ffmpeg'),
            '-y', '-v', 'error',
            '-allowed_extensions', 'ALL',
            '-protocol_whitelist', 'file,crypto',
            '-i', 'local.m3u8',
            '-c', 'copy',
            '-bsf:a', 'aac_adtstoasc',
            '-movflags', '+faststart',
            'out.mp4',
        ], $dir, null, null, (float) config('hls-videos.exports.timeout', 3600));

        $process->run();

        if (! $process->isSuccessful() || ! is_file("$dir/out.mp4")) {
            throw new \RuntimeException('ffmpeg remux failed: '.trim($process->getErrorOutput()));
        }
    }

    protected function upload(string $localFile, string $disk, string $path): void
    {
        $stream = fopen($localFile, 'r');

        try {
            if (! Storage::disk($disk)->writeStream($path, $stream, ['ContentType' => 'video/mp4'])) {
                throw new \RuntimeException("Failed to upload {$disk}:{$path}");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    protected function expiresAt()
    {
        return now()->addDays((int) config('hls-videos.exports.ttl_days', 7));
    }
}
