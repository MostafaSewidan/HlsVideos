<?php

namespace HlsVideos\Services;

use HlsVideos\Models\HlsVideo;
use HlsVideos\Models\HlsVideoQuality;
use FFMpeg;
use HlsVideos\Models\HlsFolder;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Illuminate\Support\Facades\Storage;
use HlsVideos\Jobs\ConvertQualityJob;
use Illuminate\Support\Str;
use ProtoneMedia\LaravelFFMpeg\Exporters\HLSExporter;

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

            $stream = $video->stream_data;
            $stream['thumb_disk'] = config('hls-videos.thumb_disk');
            $video->update(['stream_data' => $stream]);
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

    /**
     * Applies metadata probed elsewhere (the encoder node, which already holds
     * the file and has ffmpeg) to a video uploaded straight to R2.
     *
     * Writes the thumbnail to exactly the path and disk createThumb() uses, so
     * HlsVideo::getThumbUrlAttribute keeps working untouched.
     *
     * @param  string|null  $thumbContents  raw JPEG bytes
     */
    public function applyProbedMetadata(HlsVideo $video, array $metadata, ?string $thumbContents = null): void
    {
        $stream = $video->stream_data ?? [];

        foreach (['duration', 'width', 'height'] as $field) {
            if (isset($metadata[$field])) {
                $stream[$field] = $metadata[$field];
            }
        }

        if ($thumbContents !== null && config('hls-videos.take_thumbnail', true)) {
            try {
                Storage::disk(config('hls-videos.thumb_disk'))->put(
                    VideoService::getMediaPath()."$video->id/thumb.jpg",
                    $thumbContents
                );

                $stream['thumb_disk'] = config('hls-videos.thumb_disk');
            } catch (\Exception $e) {
                \Log::warning("Could not store probed thumbnail for video {$video->id}: ".$e->getMessage());
            }
        }

        $video->update(['stream_data' => $stream]);
    }

    public function protectVideo(HlsVideo $video)
    {
        try {
            $encryptionKey = HLSExporter::generateEncryptionKey();
            $data = $video->stream_data ?? [];
            $data['hls_key'] = base64_encode($encryptionKey);
            $data['hls_key_id'] = Str::uuid()->toString();
            $video->update(['stream_data' => $data]);
        } catch (\Exception $e) {
        }
    }

    static function getMediaPath()
    {
        return app('currentTenant')->media_folder.'/';
    }

    /**
     * Single source of truth for where the ORIGINAL video file lives on the
     * remote disk. Built server side from the video row -- never from request
     * input -- so a client cannot steer a signed upload at an arbitrary path.
     *
     * Shape: {prefix}/{tenant->media_folder}/{video_id}/{file_name}
     * which is exactly the layout the encoder nodes already expect.
     */
    static function originalKey($video): string
    {
        $prefix = trim(config('hls-videos.temp_videos_prefix', 'temp-videos'), '/');

        return $prefix.'/'.self::getMediaPath()."{$video->id}/{$video->file_name}";
    }

    /**
     * Prefix every original of the current tenant must sit under. Used as a
     * belt-and-braces check on top of per-tenant database isolation.
     */
    static function tenantOriginalPrefix(): string
    {
        $prefix = trim(config('hls-videos.temp_videos_prefix', 'temp-videos'), '/');

        return $prefix.'/'.self::getMediaPath();
    }

    /**
     * Kicks off transcoding. Split out of the HlsVideo::created hook so that a
     * row can exist -- and own a key -- before its file has been uploaded.
     * Idempotent: a video that already has qualities is left alone.
     */
    public function startProcessing($video): bool
    {
        if ($video->qualities()->exists()) {
            return false;
        }

        $this->handleVideoQualities($video);

        return true;
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

    /**
     * Creates the video row BEFORE any bytes are uploaded, so that the id --
     * and therefore the storage key -- exists at signing time.
     *
     * Mirrors handlingUploadedFile()'s model/folder attachment so both upload
     * drivers produce identical rows. The PENDING_UPLOAD status is what stops
     * the created hook from starting the encoder on an empty file.
     */
    public function createPendingVideo(
        string $originalFileName,
        string $extension,
        $model = null,
        $folderId = null,
        ?int $size = null
    ): HlsVideo {

        $extension = ltrim(strtolower($extension), '.');
        $videoId = $this->createUniqueVideoUuid();

        $video = HlsVideo::create([
            'id' => $videoId,
            'status' => HlsVideo::PENDING_UPLOAD,
            'file_name' => "vd.$extension",
            'original_extension' => $extension,
            'original_file_name' => $originalFileName,
            'upload_size' => $size,
            'upload_started_at' => now(),
        ]);

        // Key depends on file_name, which is only known once the row exists.
        $video->forceFill(['r2_key' => self::originalKey($video)])->save();

        if ($model) {
            $model->hlsVideos()->attach([$video->id]);
        }

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
        HlsVideoQuality::create([
            'hls_video_id' => $video->id,
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

    static function getStreamFileContent($videoId, $quality = null, $file = null, $domain = null)
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
            $replacePath = VideoService::getMediaPath().$videoId;
            $subdomain = VideoService::getSubDomain();
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

                $oldTsFilesUrl = "https://$subdomain.stepsio.com/api/vd/{$videoId}/stream/{$quality}/";
                $content = str_replace($oldTsFilesUrl, '', $content);
                $newTsFilesUrl = "https://stepsio-stream.org/$replacePath/{$quality}";
                $content = str_replace('index-', "$newTsFilesUrl/index-", $content);
                $content = preg_replace('/URI="[^"]*secret\.key"/', 'URI="secret.key"', $content);

            }

            if (! $file) {
                $content = str_replace('cdn.', "$subdomain.", $content);
            }

            return response($content, 200, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);

        } catch (\Exception $e) {

            \Log::error("Error getting stream file content: ".$e->getMessage()." - ".$e->getTraceAsString());
            abort(404);
        }
    }

    static function getStreamTemporaryLink($videoId, $quality = null, $file = null)
    {
        $video = HlsVideo::ready()->findOrFail($videoId);

        $path = VideoService::getMediaPath().$video->id;

        if ($quality)
            $path .= "/$quality";

        if ($file)
            $path .= "/$file";
        else
            $path .= "/index.m3u8";

        if (! Storage::disk(config('hls-videos.stream_disk'))->exists($path)) {
            return false;
        }

        // Optional: auth check
        // if (auth()->user()->cannot('view-video', $id)) abort(403);

        return Storage::disk(config('hls-videos.stream_disk'))->temporaryUrl(
            $path,
            now()->addMinutes(5) // signed URL valid for 15 minutes
        );
    }


    public static function dispatchConvertQualityJob($videoQuality): void
    {
        // Create directories
        if (! is_dir($videoQuality->process_folder_path)) {
            mkdir($videoQuality->process_folder_path, 0755, true);
        }

        ConvertQualityJob::dispatch($videoQuality, app('currentTenant'))->onQueue(config('hls-videos.convert_quality_queue_name', 'default'));
    }

    static function downloadVideoLocale($localPath, $video)
    {
        $firstQ = $video->qualities()->oldest()->first();
        $path = self::getMediaPath()."$video->id/$firstQ->quality/vd.m3u8";

        $content = Storage::disk(config('hls-videos.stream_disk'))->get($path);
        $oldTsFilesUrl = route(config('hls-videos.access_route_stream'), [$video->id, $firstQ->quality]);
        $newTsFilesUrl = "$localPath/$video->id";
        $content = str_replace($oldTsFilesUrl, $newTsFilesUrl, $content);
        $tsFiles = self::getTsFilesFromPlaylistFile($content);
        $tsFilesUrls = [];

        foreach ($tsFiles as $file) {
            $tsFilesUrls[] = [
                'folder_name' => $video->id,
                'file_name' => $file,
                "donwload_url" => route(config('hls-videos.download_route_ts_files'), [
                    $video->id, $firstQ->quality, $file
                ])
            ];
        }

        return [
            "playlist" => [
                "file_name" => "index.m3u8",
                "file_content" => $content
            ],
            "ts_files" => $tsFilesUrls
        ];
    }

    static function downloadCompressedVideoLocale($localPath, $video)
    {
        $firstQ = $video->qualities()->oldest()->first();
        $path = self::getMediaPath()."$video->id/$firstQ->quality/vd.m3u8";
        $replacePath = self::getMediaPath().$video->id;

        $content = Storage::disk(config('hls-videos.stream_disk'))->get($path);
        $oldTsFilesUrl = route(config('hls-videos.access_route_stream'), [$video->id, $firstQ->quality]);

        $content = str_replace("$oldTsFilesUrl/", '', $content);
        $newTsFilesUrl = "$localPath/.$video->id";
        $content = str_replace('index-', "$newTsFilesUrl/index-", $content);
        $secrtUri = route(config('hls-videos.access_route_stream'), [$video->id, $firstQ->quality, "secret.key"]);
        $content = str_replace('secret.key', $secrtUri, $content);

        return [
            "playlist" => [
                "file_name" => "index.m3u8",
                "file_content" => $content
            ],
            "file_data" => [
                'file_name' => 'vd.zip',
                "donwload_url" => "https://stepsio-stream.org/$replacePath/vd.zip"
            ]
        ];
    }

    static function getTsFilesFromPlaylistFile($masterPlaylistFile)
    {
        preg_match_all('/([a-zA-Z0-9_\-]+\.ts)/', $masterPlaylistFile, $matches);
        return $matches[1] ?? [];
    }
}
