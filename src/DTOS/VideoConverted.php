<?php

namespace  HlsVideos\DTOS;

use HlsVideos\Events\VideoConvertedEvent;
use  HlsVideos\Models\HlsVideo;
use  HlsVideos\Models\HlsVideoQuality;
use HlsVideos\Services\VideoService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Event;

class VideoConverted
{
   public $video;

   public function __construct(public HlsVideoQuality $videoQuality) {

      $this->video = $this->videoQuality->video;

      $this->handlingTheQualityPlaylist();
      $this->createOrUpdateMasterPlaylist();

      $this->videoQuality->updateStatusTo(HlsVideoQuality::UPLOADING);
      $this->uploadVideoToStorage();

      $this->videoQuality->updateStatusTo(HlsVideoQuality::READY);
      $this->updateVideoUploaded();
   }

   private function uploadVideoToStorage()
   {
      foreach(config('hls-videos.storages') as $key => $storage){
         $service = new $storage['service'];
         $service->uploadVideo($this->videoQuality, $storage);
      }
   }

   private function updateVideoUploaded()
   {
      $this->video->update(['status' => HlsVideo::READY]);
      $upcommingQuality = VideoService::getUpcommingQuality($this->video);

      if($upcommingQuality){

         VideoService::createQualityFromConfig($this->video,$upcommingQuality);
      }else{

         if(!$this->video->qualities()->notReady()->count()){

            Storage::disk(config('hls-videos.temp_disk'))->deleteDirectory($this->video->id);
            Event::dispatch(new VideoConvertedEvent($this->video));
          }
      }
   }

   private function handlingTheQualityPlaylist()
   {
      try {
         $playlistIndexFile = "{$this->videoQuality->process_folder_path}/vd.m3u8";
         // Read the playlist file
         $content = file_get_contents($playlistIndexFile);
         if ($content === false) {
            throw new \Exception("Could not read playlist file");
         }

         // Replace .ts file references with the custom route
         // This regex matches lines ending with .ts (optionally preceded by whitespace)
         $newContent = preg_replace_callback(
            '/^([^\r\n]*?)([a-zA-Z0-9_\-]+\.ts)$/m',
            function ($matches) {
                  $fileName = $matches[2];
                  $fileName = explode('/',$fileName);
                  $fileName = $fileName[count($fileName) - 1];
                  // If you have access to the route() helper, use it. Otherwise, build the URL manually:
                  $url = route(config('hls-videos.access_route_stream'), [
                  $this->videoQuality->hls_video_id, 
                  $this->videoQuality->quality, 
                  $fileName
               ]);
                  return $matches[1] . $url;
            },
            $content
         );

         // Write the modified content back to the file (overwrite)
         file_put_contents($playlistIndexFile, $newContent);

       } catch (\Exception $e) {
         \Log::error("FAILED ConvertQualityJob: {$e->getMessage()}");
         throw $e;
       }
   }


   /**
    * Create or update a master playlist that supports multiple qualities.
    *
    * @param array $qualities Array of qualities, each as ['quality' => ..., 'indexFileName' => ..., 'bandwidth' => ..., 'resolution' => ...]
    * @param int|string $videoId
    * @param string $basePath
    */
   private function createOrUpdateMasterPlaylist()
   {
       try {
         $masterPlaylist = "#EXTM3U\n";
         $masterPlaylist .= "#EXT-X-VERSION:3\n";

         foreach ($this->video->qualities as $quality) {
            $qualityIndexPlaylistPath = "{$this->videoQuality->hls_video_id}/{$this->videoQuality->quality}/index.m3u8";
            if(Storage::disk(config('hls-videos.temp_disk'))->exists($qualityIndexPlaylistPath)){
               $fileContents = Storage::disk(config('hls-videos.temp_disk'))->get($qualityIndexPlaylistPath);
               // Extract the first #EXT-X-STREAM-INF line if available
               $lines = explode("\n", $fileContents);
               $streamInfLine = null;
               foreach ($lines as $line) {
                  if (str_starts_with($line, '#EXT-X-STREAM-INF')) {
                     $streamInfLine = $line;
                     break;
                  }
               }
               $masterPlaylist .= "$streamInfLine\n";
            }else{

               $convertData = $quality->convert_data;
               // Set defaults if not provided
               $bandwidth = isset($convertData['bandwidth']) ? $convertData['bandwidth'] : 1000000;
               $resolution = isset($convertData['width']) ? $convertData['width'] : '1280';
               $resolution .= isset($convertData['height']) ? "x{$convertData['height']}" : 'x720';
               $masterPlaylist .= "#EXT-X-STREAM-INF:BANDWIDTH={$bandwidth},RESOLUTION={$resolution}\n";
            }

            $q = $quality->quality;
            $pathToFile = route(config('hls-videos.access_route_stream'), [$this->video->id, $q, 'vd.m3u8']);
            $masterPlaylist .= "$pathToFile\n";
         }

         $masterPath = $this->video->temp_video_folder . '/index.m3u8';
         file_put_contents($masterPath, $masterPlaylist);

       } catch (\Exception $e) {
         \Log::error("FAILED ConvertQualityJob: {$e->getMessage()}");
         throw $e;
       }
   }
}
