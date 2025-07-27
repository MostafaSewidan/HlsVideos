<?php

namespace  HlsVideos\Factories;

use  HlsVideos\Services\Contracts\VideoQualityProcessorInterface;

class VideoQualityProcessorFactory
{
   public static function make(string $quality): VideoQualityProcessorInterface
   {
      if(isset(config('hls-videos.qualities')[$quality])){
         $service = config('hls-videos.qualities')[$quality]['convert_service'];
         return new $service;
      }

      throw new \InvalidArgumentException("Unsupported processor type [$quality]");
   }
}
