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

    public static function hasDuplicateTitle(string $title, int $folderId, ?int $ignoreId = null): bool
    {
        return self::where('folder_id', $folderId)
            ->where('title', $title)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    public function generateUniqueTitle(): string
    {
        $originalTitle = $this->title ?? pathinfo($this->video->original_file_name, PATHINFO_FILENAME);
        $title = $originalTitle;
        $counter = 1;

        while (self::hasDuplicateTitle($title, $this->folder_id, $this->id)) {
            $title = "{$originalTitle} ({$counter})";
            $counter++;
        }

        return $title;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->title = $model->generateUniqueTitle();
        });

        static::updating(function ($model) {
            if ($model->isDirty('title')) {
                $model->title = $model->generateUniqueTitle();
            }
        });

        static::deleting(function ($model) {
            // Check unused videos and delete real video
            if ($model->video->parentFolders()->count() === 1) {
                $model->video->delete();
            }
        });
    }
}
