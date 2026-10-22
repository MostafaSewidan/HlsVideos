<?php

namespace  HlsVideos\Services\Storages;

use Aws\CommandPool;
use Aws\Exception\AwsException;
use  HlsVideos\Models\HlsVideoQuality;
use  HlsVideos\Services\Contracts\VideoStorageInterface;
use HlsVideos\Services\VideoService;
use Illuminate\Support\Facades\Storage;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;

class R2StorageService implements VideoStorageInterface
{
   public function uploadVideo(HlsVideoQuality $quality,$storageConfig): bool{
        try{

            $r2Disk = Storage::disk($storageConfig['disk_name']);
            $pathToTempQualityFolder = VideoService::getMediaPath()."$quality->hls_video_id/$quality->quality";

            // Get all files recursively from the local disk
            $tempDisk = Storage::disk(config('hls-videos.temp_disk'));
            $allFiles = $tempDisk->allFiles($pathToTempQualityFolder);
        
            foreach ($allFiles as $relativePath) {
                $fileContents = $tempDisk->get($relativePath);
        
                // Upload each file to R2 with the same relative path
                $r2Disk->put($relativePath, $fileContents);
            }

            $masterPlaylistContents = $tempDisk->get(VideoService::getMediaPath()."$quality->hls_video_id/index.m3u8");
            $r2Disk->put(VideoService::getMediaPath()."$quality->hls_video_id/index.m3u8", $masterPlaylistContents);
            
            return true;
        } catch (\Exception $e) {
            throw new \Exception("upload Results Failed: " . $e->getMessage());
        }
   }

    /**
     * Uploads the whole video folder (segments, playlists, key) to R2,
     * keeping the same relative paths. Files are sent concurrently and
     * streamed from disk, so neither the file count nor the file size
     * drives memory use or wall-clock time the way a one-by-one loop does.
     */
    public function uploadAllFolderWithAllFilesToR2($videoId, $storageConfig): bool
    {
        $videoRoot = VideoService::getMediaPath()."$videoId/";
        $diskName = $storageConfig['disk_name'];
        $r2Disk = Storage::disk($diskName);
        $tempDisk = Storage::disk(config('hls-videos.temp_disk'));

        $allFiles = $tempDisk->allFiles($videoRoot);

        if (empty($allFiles)) {
            throw new \Exception("upload Results Failed: no files found in {$videoRoot}");
        }

        $client = $this->s3Client($r2Disk);

        // Not an S3 disk: keep the old one-by-one behaviour there.
        if ($client === null) {
            return $this->uploadSequentially($allFiles, $r2Disk, $tempDisk);
        }

        $diskConfig = config("filesystems.disks.{$diskName}", []);
        $bucket = $diskConfig['bucket'];
        $root = trim($diskConfig['root'] ?? '', '/');
        $concurrency = (int) config('hls-videos.upload_concurrency', 20);

        // Segments first, playlists last, so a playlist never points at a segment that is not there yet.
        [$playlists, $others] = collect($allFiles)->partition(fn ($path) => str_ends_with($path, '.m3u8'));

        $started = microtime(true);
        foreach ([$others->values()->all(), $playlists->values()->all()] as $batch) {
            $failed = $this->putConcurrently($client, $bucket, $root, $batch, $tempDisk, $concurrency);

            // One more pass for whatever failed (the SDK already retries each request internally).
            if (! empty($failed)) {
                $failed = $this->putConcurrently($client, $bucket, $root, array_keys($failed), $tempDisk, $concurrency);
            }

            if (! empty($failed)) {
                $first = reset($failed);
                throw new \Exception('upload Results Failed: '.count($failed)." file(s) could not be uploaded, e.g. ".key($failed).": {$first}");
            }
        }

        \Log::info('R2 upload finished', [
            'video' => $videoId,
            'files' => count($allFiles),
            'seconds' => round(microtime(true) - $started, 1),
        ]);

        return true;
    }

    /**
     * The disk's S3 client. Laravel 9+ exposes it on the disk; Laravel 8 (the
     * encoder nodes) only through the Flysystem 1 adapter. Without this the
     * encoders fell back to one-by-one uploads, which took hours for long videos.
     */
    private function s3Client($disk)
    {
        if (method_exists($disk, 'getClient')) {
            return $disk->getClient();
        }

        try {
            $adapter = $disk->getDriver()->getAdapter();
        } catch (\Throwable $e) {
            return null;
        }

        return method_exists($adapter, 'getClient') ? $adapter->getClient() : null;
    }

    /**
     * @return array<string, string> failed relative path => error message
     */
    private function putConcurrently($client, string $bucket, string $root, array $paths, $tempDisk, int $concurrency): array
    {
        if (empty($paths)) {
            return [];
        }

        $mimeDetector = new ExtensionMimeTypeDetector();

        $commands = (function () use ($client, $bucket, $root, $paths, $tempDisk, $mimeDetector) {
            foreach ($paths as $relativePath) {
                yield $relativePath => $client->getCommand('PutObject', [
                    'Bucket' => $bucket,
                    'Key' => ltrim(($root !== '' ? $root.'/' : '').$relativePath, '/'),
                    'SourceFile' => $tempDisk->path($relativePath),
                    'ContentType' => $this->contentType($relativePath, $mimeDetector),
                ]);
            }
        })();

        $failed = [];
        $pool = new CommandPool($client, $commands, [
            'concurrency' => max(1, $concurrency),
            'rejected' => function ($reason, $relativePath) use (&$failed) {
                $failed[$relativePath] = $reason instanceof AwsException
                    ? ($reason->getAwsErrorMessage() ?: $reason->getMessage())
                    : (string) ($reason instanceof \Throwable ? $reason->getMessage() : $reason);
            },
        ]);
        $pool->promise()->wait();

        return $failed;
    }

    private function contentType(string $path, ExtensionMimeTypeDetector $detector): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'm3u8' => 'application/vnd.apple.mpegurl',
            'ts' => 'video/mp2t',
            default => $detector->detectMimeTypeFromPath($path) ?? 'application/octet-stream',
        };
    }

    private function uploadSequentially(array $allFiles, $r2Disk, $tempDisk): bool
    {
        try {
            foreach ($allFiles as $relativePath) {
                $stream = $tempDisk->readStream($relativePath);
                $r2Disk->writeStream($relativePath, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return true;
        } catch (\Exception $e) {
            throw new \Exception("upload Results Failed: " . $e->getMessage());
        }
    }
}
