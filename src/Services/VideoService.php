<?php

namespace HlsVideos\Services;

use HlsVideos\Models\HlsVideo;
use FFMpeg;
use HlsVideos\Models\HlsFolder;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Illuminate\Support\Facades\Storage;
use HlsVideos\Jobs\ConvertQualityJob;


class VideoService
{
    public function createThumb(HlsVideo $video)
    {

        if (config('hls-videos.take_thumbnail')) {

            FFMpeg::fromDisk(config('hls-videos.temp_disk'))
                ->open($video->temp_video_path)
                ->getFrameFromSeconds(3)
                ->export()
                ->toDisk(config('hls-videos.thumb_disk'))
                ->save(VideoService::getMediaPath()."$video->id/thumb.jpg");
        }
    }
    public function getVideoDuration(HlsVideo $video)
    {

        try {

            $stream = $video->stream_data;
            $stream['duration'] = FFMpeg::fromDisk(config('hls-videos.temp_disk'))
                ->open($video->temp_video_path)
                ->getDurationInSeconds();

            $video->update(['stream_data' => $stream]);

        } catch (\Exception $e) {
            // Log error but don't fail the upload
            \Log::warning("Could not extract duration for video {$video->id}: ".$e->getMessage());
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

    static function deleteVideo($request, $id)
    {
        $video = HlsVideo::find($id);
        $model = $request->modelType ? $request->modelType::find($request->modelId) : null;

        if ($model) {
            $model->hlsVideos()->detach($id);
        }

        if (! $video->HlsVideoables()->count() && ! $video->parentFolders()->count()) {

            return HlsVideo::find($id)->delete();
        }
    }

    public function receiveVideo($request, $model = null, $folderId = null)
    {
        $receiver = new FileReceiver('file', $request, HandlerFactory::classFromRequest($request));

        if (! $receiver->isUploaded()) {
            // file not uploaded
        }

        $fileReceived = $receiver->receive(); // receive file

        if ($fileReceived->isFinished()) { // file uploading is complete / all chunks are uploaded
            $file = $fileReceived->getFile(); // Get file

            $video = $this->handlingUploadedFile($file, $model, folderId: $folderId);

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

    public function handlingUploadedFile($file, $model = null, $extension = null, $originalFileName = null, $deleteChunked = true, $folderId = null)
    {

        // Store the uploaded file
        $extension = $extension ?? $file->getClientOriginalExtension();
        $fileName = "vd.$extension";

        $disk = Storage::disk(config('hls-videos.temp_disk'));

        $videoId = $this->createUniqueVideoUuid();
        $disk->putFileAs((VideoService::getMediaPath()."$videoId"), $file, $fileName);

        if ($deleteChunked) {
            // Delete chunked file
            unlink($file->getPathname());
        }

        $video = HlsVideo::create([
            'id' => $videoId,
            'file_name' => $fileName,
            'original_extension' => $extension,
            'original_file_name' => $originalFileName ?? $file->getClientOriginalName()
        ]);

        if ($model)
            $model->hlsVideos()->attach([$video->id]);

        $folder = HlsFolder::find($folderId) 
            ?? config('hls-videos.repositories.hls_folder')::mainSharedFolders(HlsFolder::query())->first();
        if ($folder) {
            $folder->videos()->attach(
                $video->id,
                ['title' => $video->original_file_name]
            );
        }

        return $video;
    }

    public function receiveFromServer($request, $videoId)
    {
        $video = HlsVideo::findOrFail($videoId);
        $file = $request->file('file');
        // Store the uploaded file
        $extension = $extension ?? $file->getClientOriginalExtension();
        $fileName = "vd.$extension";

        $disk = Storage::disk(config('hls-videos.temp_disk'));
        $disk->putFileAs((VideoService::getMediaPath()."$videoId"), $file, $fileName);

        $video->qualities()->delete();
        (new VideoService())->handleVideoQualities($video);
    }

    private function createUniqueVideoUuid(): string
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();
        if (HlsVideo::find($uuid))
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

                    $url = str_replace('cdn.', (VideoService::getSubDomain().'.'), $url);
                    return $matches[1].$url;
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
        $upcommingQuality = self::getUpcommingQuality($video);

        if ($upcommingQuality)
            self::createQualityFromConfig($video, $upcommingQuality);
    }

    static function getUpcommingQuality($video)
    {
        foreach (config('hls-videos.qualities') as $configQuality) {

            if (! $video->qualities()->where('quality', $configQuality['quality'])->exists()) {
                return $configQuality;
            }
        }

        return false;
    }

    static function createQualityFromConfig($video, $configQuality)
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

    static function getStreamTemporaryLink($videoId, $quality = null, $file = null, $domain = null)
    {
        try {
            $path = VideoService::getMediaPath().$videoId;

            if ($quality)
                $path .= "/$quality";

            if ($file)
                $path .= "/$file";
            else
                $path .= "/index.m3u8";

            $disk = Storage::disk(config('hls-videos.stream_disk'));

            $content = $disk->get($path);

            // Determine content type based on file extension
            $contentType = 'application/vnd.apple.mpegurl'; // default for .m3u8
            if ($file) {
                if (str_ends_with($file, '.ts')) {
                    $contentType = 'video/mp2t';
                } elseif (str_ends_with($file, '.m3u8')) {
                    $contentType = 'application/vnd.apple.mpegurl';
                }
            }

            if ($file == 'vd.m3u8') {

                $replacePath = VideoService::getMediaPath().$videoId;
                $subdomain = VideoService::getSubDomain();

                $oldTsFilesUrl = "https://$subdomain.stepsio.com/api/vd/{$videoId}/stream/{$quality}";
                $newTsFilesUrl = "https://stepsio-stream.org/$replacePath/{$quality}";
                $content = str_replace($oldTsFilesUrl, $newTsFilesUrl, $content);
            }

            return response($content, 200, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);

        } catch (\Exception $e) {
            abort(404);
        }
    }

    public static function dispatchConvertQualityJob($videoQuality): void
    {
        // Create directories
        if (! is_dir($videoQuality->process_folder_path)) {
            mkdir($videoQuality->process_folder_path, 0755, true);
        }

        ConvertQualityJob::dispatch($videoQuality, app('currentTenant'))->onQueue('default');
    }

    static function downloadVideoLocale($videoId, $quality)
    {
        try {
            $originalPath = VideoService::getMediaPath().$videoId;
            $path = "$originalPath/$quality/index.m3u8";
            $content = Storage::disk(config('hls-videos.stream_disk'))->get($path);

            $oldTsFilesUrl = route(config('hls-videos.access_route_stream'), [$videoId, $quality]);
            $newTsFilesUrl = "https://stepsio-stream.org/$originalPath/{$quality}";
            $content = str_replace($oldTsFilesUrl, $newTsFilesUrl, $content);
            $tsFiles = [];

            if (strpos($content, '#EXT-X-STREAM-INF') !== false) {
                preg_match('/([a-zA-Z0-9_\-]+\.m3u8)/', $content, $match);
                if (!empty($match[1])) {
                    $variantPath = "$originalPath/$quality/" . $match[1];
                    $variantContent = Storage::disk(config('hls-videos.stream_disk'))->get($variantPath);
                    $tsFiles = self::getTsFilesFromPlaylistFile($variantContent);
                }
            } else {
                $tsFiles = self::getTsFilesFromPlaylistFile($content);
            }
            $tsFilesUrls = [];

            foreach ($tsFiles as $file) {
                $tsFilesUrls[] = [
                    'folder_name' => $videoId,
                    'file_name' => $file
                ];
            }

            return [
                "playlist" => [
                    "file_name" => "index.m3u8",
                    "file_content" => $content
                ],
                "ts_files" => $tsFilesUrls
            ];
        } catch (\Throwable $th) {
            abort(404);
        }
    }

    static function getTsFilesFromPlaylistFile($masterPlaylistFile)
    {
        preg_match_all('/([a-zA-Z0-9_\-]+\.ts)/', $masterPlaylistFile, $matches);
        return $matches[1] ?? [];
    }
}
