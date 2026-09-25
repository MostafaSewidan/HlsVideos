<?php

namespace HlsVideos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use HlsVideos\Services\VideoService;

class HlsVideo extends Model
{

    const PENDING_UPLOAD = 'pending_upload';
    const UPLOAD_FAILED = 'upload_failed';
    const UPLOADED = 'uploaded';
    const PROCESSING = 'processing';
    const READY = 'ready';
    const ORIENTATION_PORTRAIT = 'P';
    const ORIENTATION_LANDSCAPE = 'L';
    protected $guarded = [];
    public $casts = [
        'stream_data' => 'array',
        'upload_started_at' => 'datetime',
    ];
    public $incrementing = false;
    protected $keyType = 'string';

    protected static function boot()
    {
        parent::boot();

        static::created(function ($video) {
            $videoService = new VideoService;

            // Direct-to-R2: the row exists so that its id can be used to build
            // the storage key, but no bytes have been uploaded yet. Probing the
            // file or starting the encoder here would act on nothing --
            // DirectUploadController::complete() does both once the upload
            // actually finishes.
            if ($video->status === self::PENDING_UPLOAD) {
                $videoService->protectVideo($video);

                return;
            }

            $videoService->createThumb($video);
            $videoService->getVideoDuration($video);
            $videoService->protectVideo($video);
            $videoService->handleVideoQualities($video);
        });

        static::deleting(function ($video) {
            $video->qualities()->delete();

            foreach (config('hls-videos.storages') as $disk => $config) {
                Storage::disk($disk)->deleteDirectory(VideoService::getMediaPath().$video->id);
            }

            // The original lives under a different prefix than the HLS output
            // and was previously left behind on every delete.
            try {
                $originalDisk = config('hls-videos.uploaded_videos_disk');
                $prefix = trim(config('hls-videos.temp_videos_prefix', 'temp-videos'), '/');

                Storage::disk($originalDisk)->deleteDirectory(
                    $prefix.'/'.VideoService::getMediaPath().$video->id
                );
            } catch (\Exception $e) {
                \Log::warning("Could not delete original for video {$video->id}: ".$e->getMessage());
            }
        });
    }

    public function scopePendingUpload($query)
    {
        return $query->where('status', self::PENDING_UPLOAD);
    }

    /**
     * Remote key of the original file. Prefers the value stored at init time,
     * falling back to recomputing it for rows created by the legacy driver.
     */
    public function getOriginalKeyAttribute(): string
    {
        return $this->r2_key ?: VideoService::originalKey($this);
    }

    public function parentFolders()
    {
        return $this->belongsToMany(
            HlsFolder::class,
            'hls_folder_video',
            'hls_video_id',
            'folder_id'
        )->using(HlsFolderVideo::class)   //! مهم عشان يعدي علي البوت بتاع موديل HlsFolderVideo 
            ->withPivot('id', 'title')
            ->withTimestamps();
    }

    public function qualities()
    {
        return $this->hasMany(HlsVideoQuality::class, 'hls_video_id');
    }

    // This relation is likely incorrect.
    // If you want to get all models (of any type) that are related to this HlsVideo,
    // you should use morphToMany, not morphByMany, and the related model should not be HlsVideo itself.
    // Typically, the inverse of a morphToMany is a morphedByMany.
    // For example, if HlsVideo is related to other models via 'videoable', you might want:

    public function videoables()
    {
        return $this->morphedByMany(
            config('hls-videos.videoable_models', []), // or specify the model(s) you expect, e.g. User::class, Post::class, etc.
            'videoable',
            'hls_videoables',
            'hls_video_id',
            'videoable_id'
        );
    }

    public function HlsVideoables()
    {
        return $this->hasMany(
            HlsVideoable::class,
            'hls_video_id',
            'id'
        );
    }

    public function scopeReady($query)
    {
        return $query->where('status', self::READY);
    }

    public function getThumbUrlAttribute()
    {
        $stream = $this->stream_data;
        $thumbPath = VideoService::getMediaPath()."$this->id/thumb.jpg";
        if (isset($stream['thumb_disk'])) {
            return "https://stepsio-stream.org/".$thumbPath;
        } else {
            return Storage::disk(config('hls-videos.thumb_disk'))->url($thumbPath);
        }
    }

    public function getTempVideoAttribute()
    {

        $path = VideoService::getMediaPath()."{$this->id}/{$this->file_name}";
        return Storage::disk(config('hls-videos.temp_disk'))->exists($path) ? Storage::disk(config('hls-videos.temp_disk'))->path($path) : null;
    }

    public function getTempVideoFolderAttribute()
    {

        $path = VideoService::getMediaPath()."{$this->id}";
        return Storage::disk(config('hls-videos.temp_disk'))->exists($path) ? Storage::disk(config('hls-videos.temp_disk'))->path($path) : null;
    }

    public function getTempVideoPathAttribute()
    {

        return VideoService::getMediaPath()."{$this->id}/{$this->file_name}";
    }

    public function getTempFolderPathAttribute()
    {

        return VideoService::getMediaPath()."{$this->id}";
    }

    public function getIsReadyAttribute()
    {

        return $this->status == self::READY;
    }

    public function getVideoLinkAttribute()
    {

        return route(config('hls-videos.access_route_stream'), [$this->id]);
    }

    public function getOriginalVideoLinkAttribute()
    {
        return "https://stepsio-stream.org/temp-videos/".VideoService::getMediaPath().$this->id."/{$this->file_name}";
    }

    public function getIsSupportOriginalAttribute()
    {
        return false;
        // return isset($this->stream_data['support_original']) && $this->stream_data['support_original'] && $this->status != self::READY;
    }
}
