<?php

namespace HlsVideos\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class HlsFolderVideo extends Pivot
{
    protected $table = 'hls_folder_video';
    public $timestamps = true;

    protected $fillable = ['hls_video_id', 'folder_id', 'title'];

    public function folder()
    {
        return $this->belongsTo(HlsFolder::class, 'folder_id');
    }

    public function video()
    {
        return $this->belongsTo(HlsVideo::class, 'hls_video_id');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $originalTitle = $model->title ?? pathinfo($model->video->original_file_name, PATHINFO_FILENAME);
            $title = $originalTitle;
            $counter = 1;

            while (self::where('folder_id', $model->folder_id)->where('title', $title)->exists()) {
                $title = $originalTitle . " ({$counter})";
                $counter++;
            }
            $model->title = $title;
        });

        static::deleting(function ($model) {
            // Check unused videos and delete real video
            if ($model->video->parentFolders()->count() === 1) {
                $model->video->delete();
            }
        });
    }
}
