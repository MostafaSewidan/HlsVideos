<?php

namespace  HlsVideos\Services;

use  HlsVideos\Models\HlsVideo;
use FFMpeg;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Illuminate\Support\Facades\Storage;


class VideoService
{
    public function createThumb(HlsVideo $video){

        if(config('hls-videos.take_thumbnail')){

            FFMpeg::fromDisk(config('hls-videos.temp_disk'))
            ->open($video->temp_video_path)
            ->getFrameFromSeconds(3)
            ->export()
            ->toDisk(config('hls-videos.thumb_disk'))
            ->save(VideoService::getMediaPath()."$video->id/thumb.jpg");
        }
    }

    static function getMediaPath()
    {
        return app('currentTenant')->media_folder.'/';
    }

    static function getSubDomain()
    {
        return app('currentTenant')->subdomain;
    }

    static function findById($id)
    {
        return HlsVideo::find($id);
    }

    static function deleteVideo($id)
    {
        return HlsVideo::find($id)->delete();
    }

    public function receiveVideo($request,$model = null)
    {
        $receiver = new FileReceiver('file', $request, HandlerFactory::classFromRequest($request));
        
        if (!$receiver->isUploaded()) {
            // file not uploaded
        }

        $fileReceived = $receiver->receive(); // receive file
        
        if ($fileReceived->isFinished()) { // file uploading is complete / all chunks are uploaded
            $file = $fileReceived->getFile(); // Get file

            $video = $this->handlingUploadedFile($file, $model);
            
            return [
                "status" => true,
                "message" => "File uploaded successfully",
                "video" => $video
            ];
        }

        // otherwise return percentage information
        $handler = $fileReceived->handler();
        return [
            'done' => $handler->getPercentageDone(),
            'status' => true
        ];
    }

    public function handlingUploadedFile($file,$model = null,$extension = null,$originalFileName = null, $deleteChunked = true) {
       
        // Store the uploaded file
        $extension = $extension ?? $file->getClientOriginalExtension();
        $fileName = "vd.$extension";

        $disk = Storage::disk(config('hls-videos.temp_disk'));

        $videoId = $this->createUniqueVideoUuid();
        $disk->putFileAs((VideoService::getMediaPath()."$videoId"), $file, $fileName);

        if($deleteChunked){
            // Delete chunked file
            unlink($file->getPathname());
        }

        $video = HlsVideo::create([
            'id' => $videoId,
            'file_name' => $fileName,
            'original_extension' => $extension,
            'original_file_name' => $originalFileName ?? $file->getClientOriginalName()
        ]);

        if($model) $model->hlsVideos()->attach([$video->id]);

        return $video;
    }

    private function createUniqueVideoUuid() : string {
        $uuid = (string) \Illuminate\Support\Str::uuid();
        if(HlsVideo::find($uuid))
            return $this->createUniqueVideoUuid();
        else
            return $uuid;
    }
    

    public function handlingTheQualityPlaylist($q, $videoId, $playlistIndexFile)
    {
        try {
            // Read the playlist file
            $content = file_get_contents($playlistIndexFile);
            if ($content === false) {
                throw new \Exception("Could not read playlist file: $playlistIndexFile");
            }

            // Replace .ts file references with the custom route
            // This regex matches lines ending with .ts (optionally preceded by whitespace)
            $newContent = preg_replace_callback(
                '/^([^\r\n]*?)([a-zA-Z0-9_\-]+\.ts)$/m',
                function ($matches) use ($q, $videoId) {
                    $fileName = $matches[2];
                    // If you have access to the route() helper, use it. Otherwise, build the URL manually:
                    $url = route(config('hls-videos.access_route_stream'), [$videoId, $q, $fileName]);
                    
                    $url = str_replace('cdn.',(VideoService::getSubDomain().'.'),$url);
                    return $matches[1] . $url;
                },
                $content
            );

            // Write the modified content back to the file (overwrite)
            file_put_contents($playlistIndexFile, $newContent);

        } catch (\Exception $e) {
            // Handle error as needed
        }
    }

    public function handleVideoQualities($video)
    {
        // $videoStream = $this->getVideoInfo($video);
        // $height = $videoStream['height'];
        // // logger('config(hls-videos.qualities)',$videoStream);

        // // Match height to quality label
        // $quality = match (true) {
        //     $height >= 1080 => '1080',
        //     $height >= 720  => '720',
        //     $height >= 480  => '480',
        //     $height >= 360  => '360',
        //     $height >= 144  => '144',
        //     default         => 'original',
        // };

        // $video->update([
        //     'original_steam_quality' => $quality,
        //     'stream_data' => $videoStream,
        // ]);
        $upcommingQuality = self::getUpcommingQuality($video);

        if($upcommingQuality)
            self::createQualityFromConfig($video,$upcommingQuality);
    }

    static function getUpcommingQuality($video)
    {
        foreach(config('hls-videos.qualities') as $configQuality){

            if(!$video->qualities()->where('quality',$configQuality['quality'])->exists()){
                return $configQuality;
            }
        }

        return false;
    }

    static function createQualityFromConfig($video,$configQuality)
    {
        $video->qualities()->create([
            'quality' => $configQuality['quality'],
            'convert_service' => $configQuality['convert_service'],
        ]);
    }

    public function getVideoInfo($video)
    {
        $video = FFMpeg::fromDisk(config('hls-videos.temp_disk'))
            ->open($video->temp_video_path);
       
        $duration = $video->getDuration(); // Duration in seconds
        $frame = $video->getFrame(0); // Get a frame (e.g., the first frame)
        $dimension = $frame->getDimensions(); // Get the dimension

        return [
            'duration' => $duration,
            'width' => $dimension->getWidth(),
            'height' => $dimension->getHeight(),
        ];
    }

    static function getStreamTemporaryLink($videoId,$quality = null, $file = null)
    {
        $video = HlsVideo::ready()->findOrFail($videoId);

        $path = VideoService::getMediaPath().$video->id;
        
        if($quality)
            $path .= "/$quality";

        if($file)
            $path .= "/$file";
        else
            $path .= "/index.m3u8";
        
        if (!Storage::disk(config('hls-videos.stream_disk'))->exists($path)) {
            abort(404);
        }
    
        // Optional: auth check
        // if (auth()->user()->cannot('view-video', $id)) abort(403);
    
        return Storage::disk(config('hls-videos.stream_disk'))->temporaryUrl(
            $path,
            now()->addMinutes(5) // signed URL valid for 15 minutes
        );
    }
}
