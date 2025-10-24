<?php

namespace HlsVideos\Models;

use Illuminate\Database\Eloquent\Model;

class HlsFolder extends Model
{
    protected $table = 'hls_folders';
    public $timestamps = true;

    protected $fillable = ['title', 'parent_id'];

    public function getAllChildrenIdsAttribute()
    {
        $childrenIds = [];

        foreach ($this->relatedFolders()->withoutGlobalScopes(['checkSharedFolders'])->get() as $child) {
            $childrenIds[] = $child->id;
            $childrenIds = array_merge($childrenIds, $child->all_children_ids);
        }

        return $childrenIds;
    }

    public function getBreadcrumbAttribute()
    {
        $hlsFolderRepository = config('hls-videos.repositories.hls_folder');
        $breadcrumb = collect([]);
        $folder = $this;
        $visitedIDS = [];

        if ($hlsFolderRepository::isSharedFolders()) {
            $allSharedIds = $hlsFolderRepository::allSharedIds();

            while (!is_null($folder)) {
                if (!in_array($folder->id, $allSharedIds) || in_array($folder->id, $visitedIDS))
                    break;

                $breadcrumb->prepend($folder);
                $visitedIDS[] = $folder->id;
                $folder = $folder->parent;
            }
        } else {
            while (!is_null($folder)) {
                if (in_array($folder->id, $visitedIDS))
                    break;

                $breadcrumb->prepend($folder);
                $visitedIDS[] = $folder->id;
                $folder = $folder->parent;
            }
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
            ->withPivot(['id', 'title', 'created_at', 'updated_at'])
            ->withTimestamps();
    }

    public function parent()
    {
        return $this->belongsTo(HlsFolder::class, 'parent_id');
    }

    public function relatedFolders()
    {
        return $this->hasMany(HlsFolder::class, 'parent_id');
    }

    public function scopeWithRelations($query)
    {
        return $query->with(['relatedFolders', 'videos']);
    }

    public static function hasDuplicateTitle(string $title, int $parentId, ?int $ignoreId = null): bool
    {
        return self::where('parent_id', $parentId)
            ->where('title', $title)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    public function generateUniqueTitle(): string
    {
        $originalTitle = $this->title;
        $title = $originalTitle;
        $counter = 1;

        if($this->parent_id){
            while (self::hasDuplicateTitle($title, $this->parent_id, $this->id)) {
                $title = "{$originalTitle} ({$counter})";
                $counter++;
            }
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
            // داله للتأكيد
            if ($model->isDirty('title')) {
                $model->title = $model->generateUniqueTitle();
            }
        });

        static::deleting(function ($model) {
            $model->videos()->detach();
            $model->relatedFolders()->delete();
        });
    }

    protected static function booted()
    {
        parent::booted();

        static::addGlobalScope('checkSharedFolders', function ($query) {
            $repositoryClass = config('hls-videos.repositories.hls_folder');

            if (class_exists($repositoryClass) && method_exists($repositoryClass, 'checkSharedFolders')) {
                $repositoryClass::checkSharedFolders($query);
            }
        });
    }
}
