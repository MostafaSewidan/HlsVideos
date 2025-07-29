<?php

namespace  HlsVideos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use  HlsVideos\Services\VideoService;

class HlsVideo extends Model
{

    const UPLOADED = 'uploaded';
    const PROCESSING = 'processing';
    const READY = 'ready';
    protected $guarded = [];
    public $casts = ['stream_data' => 'array'];
    public $incrementing = false;
    protected $keyType = 'string';

    protected static function boot()
    {
        parent::boot();

        static::created(function ($video) {
            $videoService = new VideoService;
            $videoService->createThumb($video);
            $videoService->handleVideoQualities($video);
        });

        static::deleting(function ($video) {
            $video->qualities()->delete();
            foreach(config('hls-videos.storages') as $disk => $config){
                Storage::disk($disk)->deleteDirectory(VideoService::getMediaPath().$video->id);
            }
        });
    }

    public function qualities(){
        return $this->hasMany(HlsVideoQuality::class,'hls_video_id');
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

    public function scopeReady($query){
        return $query->where('status', self::READY);
    }

    public function getThumbUrlAttribute(){

        $thumbPath = VideoService::getMediaPath()."$this->id/thumb.jpg";
        return Storage::disk(config('hls-videos.thumb_disk'))->url($thumbPath);
    }

    public function getTempVideoAttribute(){

        $path = VideoService::getMediaPath()."{$this->id}/{$this->file_name}";
        return Storage::disk(config('hls-videos.temp_disk'))->exists($path) ? Storage::disk(config('hls-videos.temp_disk'))->path($path) : null;
    }

    public function getTempVideoFolderAttribute(){

        $path = VideoService::getMediaPath()."{$this->id}";
        return Storage::disk(config('hls-videos.temp_disk'))->exists($path) ? Storage::disk(config('hls-videos.temp_disk'))->path($path) : null;
    }

    public function getTempVideoPathAttribute(){

        return VideoService::getMediaPath()."{$this->id}/{$this->file_name}";
    }

    public function getIsReadyAttribute(){

        return $this->status == self::READY;
    }

    public function getVideoLinkAttribute(){

        return route(config('hls-videos.access_route_stream'),[$this->id]);
    }
}
