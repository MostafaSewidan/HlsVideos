<?php

namespace  HlsVideos\Services\Storages;

use  HlsVideos\Models\HlsVideoQuality;
use  HlsVideos\Services\Contracts\VideoStorageInterface;
use HlsVideos\Services\VideoService;
use Illuminate\Support\Facades\Storage;

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
}
