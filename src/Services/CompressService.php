<?php

namespace HlsVideos\Services;

use HlsVideos\Models\HlsVideo;
use Illuminate\Support\Facades\Storage;
use HlsVideos\Services\VideoService;


class CompressService
{
    static function compressAndUploadVideo(HlsVideo $video)
    {
        $qualities = config('hls-videos.qualities');
        $firstQuality = reset($qualities);
        $quality = $video->qualities()->where('quality', $firstQuality['quality'])->first();
        $folder = VideoService::getMediaPath()."{$video->id}/{$quality->quality}";
        $tempDisk = Storage::disk(config('hls-videos.temp_disk'));

        try {
            $allFiles = $tempDisk->allFiles($folder);

            // Filter to only include .ts files
            $files = array_filter($allFiles, function ($file) {
                return strtolower(substr($file, -3)) === '.ts';
            });

            if (empty($files)) {
                throw new \Exception("No .ts files found in folder: $folder");
            }

            $tempZipPath = sys_get_temp_dir().'/'.uniqid('r2_zip_', true).'.zip';
            $zip = new \ZipArchive();

            if ($zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \Exception('Cannot create zip file');
            }

            // Use streams instead of loading entire files into memory
            foreach ($files as $file) {
                try {
                    $stream = $tempDisk->readStream($file);

                    if ($stream === false) {
                        \Log::warning("Failed to read stream from R2: $file");
                        continue;
                    }

                    $relativePath = str_replace($folder.'/', '', $file);
                    if ($relativePath === $file) {
                        $relativePath = basename($file);
                    }

                    // Create temp file for this stream
                    $tempFile = tempnam(sys_get_temp_dir(), 'r2_file_');
                    file_put_contents($tempFile, $stream);

                    // Add to zip
                    $zip->addFile($tempFile, $relativePath);

                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    // Register for cleanup after zip is closed
                    register_shutdown_function(function () use ($tempFile) {
                        @unlink($tempFile);
                    });

                } catch (\Exception $e) {
                    \Log::warning("Error processing file $file: ".$e->getMessage());
                    continue;
                }
            }

            $zip->close();

            // Upload using stream
            $stream = fopen($tempZipPath, 'r');
            foreach (config('hls-videos.storages') as $key => $storage) {

                $disk = Storage::disk($key);
                $uploaded = $disk->put(VideoService::getMediaPath()."{$video->id}/vd.zip", $stream);
            }

            if (is_resource($stream)) {
                fclose($stream);
            }

            if (! $uploaded) {
                throw new \Exception('Failed to upload zip to R2');
            }

            @unlink($tempZipPath);

            return true;
        } catch (\Exception $e) {
            if (isset($tempZipPath) && file_exists($tempZipPath)) {
                @unlink($tempZipPath);
            }

            \Log::error("Error compressing R2 folder: ".$e->getMessage(), [
                'video_id' => $video->id,
                'folder' => $folder
            ]);

            throw $e;
        }
    }
}
