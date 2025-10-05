<?php

namespace HlsVideos\Models;

use Illuminate\Database\Eloquent\Model;

class HlsFolder extends Model
{
    protected $table = 'hls_folders';
    public $timestamps = true;

    protected $fillable = ['title', 'parent_id'];

    public function getBreadcrumbAttribute()
    {
        $breadcrumb = collect([]);
        $folder = $this;

        while ($folder) {
            $breadcrumb->prepend($folder);
            $folder = $folder->parent;
        }

        return $breadcrumb;
    }


    public function videos()
    {
        return $this->belongsToMany(
            HlsVideo::class,
            'hls_folder_video',
            'folder_id',
            'hls_video_id'
        )->using(HlsFolderVideo::class)   //! مهم عشان يعدي علي البوت بتاع موديل HlsFolderVideo
            ->withPivot('id', 'title');
    }

    public function parent()
    {
        return $this->belongsTo(HlsFolder::class, 'parent_id');
    }

    public function relatedFolders()
    {
        return $this->hasMany(HlsFolder::class, 'parent_id');
    }

    public function scopeMasters($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeWithRelations($query)
    {
        return $query->with(['relatedFolders', 'videos']);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $originalTitle = $model->title;
            $title = $originalTitle;
            if ($model->parent_id) {
                $counter = 1;
                while ($model->parent->relatedFolders()->where('title', $title)->exists()) {
                    $title = $originalTitle . " ({$counter})";
                    $counter++;
                }
            }
            $model->title = $title;
        });

        static::deleting(function ($model) {
            $model->videos()->detach();
            $model->relatedFolders()->delete();
        });
    }
}
