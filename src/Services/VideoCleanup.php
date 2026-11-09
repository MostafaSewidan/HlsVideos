<?php

namespace HlsVideos\Services;

use HlsVideos\Exceptions\LinkedVideosException;
use HlsVideos\Jobs\DeleteHlsVideoFiles;
use HlsVideos\Models\HlsFolder;
use HlsVideos\Models\HlsFolderVideo;
use HlsVideos\Models\HlsVideo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What happens to a video when it stops being used (base_backend
 * docs/video-archive-plan.md, decisions 19 to 24):
 *
 *   lost its last lesson               soft deleted, purged after a few days
 *   taken off a lesson, still in the
 *   library                            kept (archived later, phase 5)
 *   deleted from the library           deleted now, or refused while a lesson uses it
 */
class VideoCleanup
{
    /** After a lesson using the video is deleted. True when the video was trashed. */
    public function trashIfUnlinked(HlsVideo $video): bool
    {
        if ($video->trashed() || $video->isLinked()) {
            return false;
        }

        return (bool) $video->delete();
    }

    /** After the video is taken off a lesson that stays. True when the video was trashed. */
    public function afterRemovedFromLesson(HlsVideo $video): bool
    {
        if ($video->trashed() || $video->isLinked() || $video->isInLibrary()) {
            return false;
        }

        return (bool) $video->delete();
    }

    /** Hides the video now and removes its files and row in the background. */
    public function deleteNow(HlsVideo $video): void
    {
        if (! $video->trashed()) {
            $video->delete();
        }
        DeleteHlsVideoFiles::dispatch($video->id, app('currentTenant'))->afterCommit();
    }

    /**
     * Deletes library entries (hls_folder_video ids). A video that leaves the
     * library with them is deleted for good; if a lesson still uses one of those,
     * nothing is deleted.
     *
     * @return int how many videos were deleted
     *
     * @throws LinkedVideosException
     */
    public function deleteFolderEntries(array $entryIds): int
    {
        $entries = HlsFolderVideo::query()->whereIn('id', $entryIds)->get(['id', 'hls_video_id', 'title']);

        return $this->removeEntries($entries, function () use ($entries) {
            HlsFolderVideo::withoutGlobalScope('checkSharedFolders')->whereIn('id', $entries->pluck('id'))->delete();
        });
    }

    /**
     * Deletes folders, their subfolders and their entries, with the same rule as
     * deleteFolderEntries().
     *
     * @return int how many videos were deleted
     *
     * @throws LinkedVideosException
     */
    public function deleteFolders(array $folderIds): int
    {
        $folders = HlsFolder::query()->whereIn('id', $folderIds)->get();
        $allIds = $folders->flatMap(fn (HlsFolder $f) => array_merge([$f->id], $f->all_children_ids))->unique()->values();
        $entries = HlsFolderVideo::withoutGlobalScope('checkSharedFolders')
            ->whereIn('folder_id', $allIds)
            ->get(['id', 'hls_video_id', 'title']);

        return $this->removeEntries($entries, function () use ($allIds) {
            // Entries and subfolders go with the folders (foreign keys cascade).
            HlsFolder::withoutGlobalScopes()->whereIn('id', $allIds)->delete();
        });
    }

    private function removeEntries(Collection $entries, callable $delete): int
    {
        $leaving = $this->videosLeavingLibrary($entries);
        $blocked = $leaving->filter(fn (HlsVideo $video) => $video->isLinked());
        if ($blocked->isNotEmpty()) {
            throw new LinkedVideosException($this->describe($blocked, $entries));
        }

        DB::transaction(function () use ($delete, $leaving) {
            $delete();
            $leaving->each(fn (HlsVideo $video) => $this->deleteNow($video));
        });

        return $leaving->count();
    }

    /** Videos with no library entry left once $entries are gone. */
    private function videosLeavingLibrary(Collection $entries): Collection
    {
        $videoIds = $entries->pluck('hls_video_id')->unique();
        if ($videoIds->isEmpty()) {
            return collect();
        }
        $staying = HlsFolderVideo::withoutGlobalScope('checkSharedFolders')
            ->whereIn('hls_video_id', $videoIds)
            ->whereNotIn('id', $entries->pluck('id'))
            ->distinct()
            ->pluck('hls_video_id');

        return HlsVideo::query()->whereIn('id', $videoIds->diff($staying))->get();
    }

    /** [['video' => title, 'used_in' => [label, ...]], ...] */
    private function describe(Collection $videos, Collection $entries): array
    {
        return $videos->map(function (HlsVideo $video) use ($entries) {
            $title = optional($entries->firstWhere('hls_video_id', $video->id))->title
                ?: pathinfo((string) $video->original_file_name, PATHINFO_FILENAME);

            return [
                'id' => $video->id,
                'video' => $title,
                'used_in' => VideoUsage::liveModels($video->id)->map(fn (Model $m) => static::label($m))->values()->all(),
            ];
        })->values()->all();
    }

    /** How a lesson (or other videoable) is named in the warning. */
    public static function label(Model $model): string
    {
        if (method_exists($model, 'hlsVideoUsageLabel')) {
            return (string) $model->hlsVideoUsageLabel();
        }

        return (string) ($model->title ?? class_basename($model).' #'.$model->getKey());
    }
}
