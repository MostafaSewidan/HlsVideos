<?php

namespace HlsVideos\Services\Download;

use HlsVideos\Exceptions\DownloadUnavailableException;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Services\VideoService;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileNotFoundException as FlysystemFileNotFound;

/**
 * Reads what an offline download is made of, on every request, the same way
 * the stream reads vd.m3u8 on every play: segment order and durations from
 * vd.m3u8, sizes from one listing of the quality folder. Nothing is written.
 *
 * The download Worker (steps-io/steps-download-worker, src/source.ts) reads the
 * same two things and must compute the same version().
 */
class DownloadSource
{
    // The only bucket the Worker has a binding for today.
    const BUCKETS = [HlsVideo::STORAGE_LEGACY];

    const SEGMENT_NAME = '/^[A-Za-z0-9._-]+\.ts$/';
    const QUALITY_NAME = '/^[A-Za-z0-9_-]{1,16}$/';

    // Always the stream disk. Not a constructor argument on purpose: the
    // container would fill a Filesystem argument with the default disk.
    private ?Filesystem $disk = null;

    /**
     * @throws DownloadUnavailableException
     */
    public function for(HlsVideo $video, string $quality): DownloadSourceData
    {
        $bucket = $this->assertDownloadable($video, $quality);
        $prefix = VideoService::getMediaPath()."{$video->id}/{$quality}/";

        $playlist = $this->readPlaylist($prefix.'vd.m3u8');
        $entries = self::playlistSegments($playlist);
        if (! $entries) {
            throw new DownloadUnavailableException(DownloadUnavailableException::BROKEN, "{$prefix}vd.m3u8 has no segments");
        }

        $sizes = $this->listSizes($prefix);
        $segments = [];
        foreach ($entries as [$name, $duration]) {
            if (! preg_match(self::SEGMENT_NAME, $name)) {
                throw new DownloadUnavailableException(DownloadUnavailableException::BROKEN, "Bad segment name {$name} in {$prefix}vd.m3u8");
            }
            $size = $sizes[$name] ?? 0;
            if ($size <= 0) {
                throw new DownloadUnavailableException(DownloadUnavailableException::BROKEN, "Segment {$prefix}{$name} is missing or empty");
            }
            $segments[] = [$name, $size, $duration];
        }

        return new DownloadSourceData(
            $bucket,
            $prefix,
            $quality,
            self::version($segments),
            $segments,
            array_sum(array_column($segments, 1)),
            round(array_sum(array_column($segments, 2)), 3),
            $playlist,
        );
    }

    /**
     * Segment names (the last part of each URI, query dropped) and #EXTINF
     * durations, in play order. Old playlists use absolute URLs
     * (https://{sub}.stepsio.com/api/vd/{id}/stream/{q}/index-000.ts), newer ones the name only.
     *
     * @return array<int, array{0: string, 1: float}>
     */
    public static function playlistSegments(string $playlist): array
    {
        $segments = [];
        $duration = 0.0;
        foreach (preg_split('/\r?\n/', $playlist) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#EXTINF:')) {
                $duration = (float) substr($line, 8);
            } elseif (! str_starts_with($line, '#')) {
                $path = explode('?', $line, 2)[0];
                $segments[] = [basename($path), $duration];
                $duration = 0.0;
            }
        }

        return $segments;
    }

    /**
     * First 16 hex chars of sha256("name:size" lines). Changes when the video is
     * converted again. Must match the Worker's sourceVersion().
     */
    public static function version(array $segments): string
    {
        $lines = array_map(fn (array $s) => "{$s[0]}:{$s[1]}", $segments);

        return substr(hash('sha256', implode("\n", $lines)), 0, 16);
    }

    private function assertDownloadable(HlsVideo $video, string $quality): string
    {
        if (! preg_match(self::QUALITY_NAME, $quality)) {
            throw new DownloadUnavailableException(DownloadUnavailableException::NOT_FOUND, "Bad quality name {$quality}");
        }
        if ($video->status !== HlsVideo::READY) {
            throw new DownloadUnavailableException(DownloadUnavailableException::NOT_READY, "Video {$video->id} is {$video->status}");
        }
        $state = $video->storage_state ?: HlsVideo::STORAGE_HOT;
        if ($state !== HlsVideo::STORAGE_HOT) {
            throw new DownloadUnavailableException(DownloadUnavailableException::NOT_HOT, "Video {$video->id} is {$state}");
        }
        $bucket = $video->storage_bucket ?: HlsVideo::STORAGE_LEGACY;
        if (! in_array($bucket, self::BUCKETS, true)) {
            throw new DownloadUnavailableException(DownloadUnavailableException::BUCKET, "Video {$video->id} is in the {$bucket} bucket");
        }

        return $bucket;
    }

    private function readPlaylist(string $path): string
    {
        try {
            $playlist = $this->disk()->get($path);
        } catch (FileNotFoundException|FlysystemFileNotFound $e) {
            $playlist = null;
        }
        if (! is_string($playlist) || $playlist === '') {
            throw new DownloadUnavailableException(DownloadUnavailableException::NOT_FOUND, "{$path} not found");
        }

        return $playlist;
    }

    /**
     * Name => size of the files directly in $prefix. One S3 ListObjects call per
     * 1,000 files (the adapter follows the pages).
     *
     * @return array<string, int>
     */
    private function listSizes(string $prefix): array
    {
        $sizes = [];
        foreach ($this->disk()->getDriver()->listContents(rtrim($prefix, '/'), false) as $item) {
            // Flysystem 1 returns arrays; 3 returns StorageAttributes.
            if (is_array($item)) {
                if (($item['type'] ?? null) !== 'file') {
                    continue;
                }
                $sizes[basename($item['path'])] = (int) ($item['size'] ?? 0);
            } elseif ($item->isFile()) {
                $sizes[basename($item->path())] = (int) $item->fileSize();
            }
        }

        return $sizes;
    }

    private function disk(): Filesystem
    {
        return $this->disk ??= Storage::disk(config('hls-videos.stream_disk'));
    }
}
