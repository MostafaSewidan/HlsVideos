<?php

namespace HlsVideos\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Whether a video is still used by a lesson (or any other videoable).
 *
 * A row in hls_videoables is live when the model it points at still has a row,
 * soft deleted (in the trash) included: a trashed lesson can be restored. A row
 * whose model is gone is dead. Rows of a type whose class no longer exists count
 * as live, so they never get a video deleted.
 *
 * Library folders don't count: a video only in folders is not linked.
 */
class VideoUsage
{
    public static function isLinked(string $videoId): bool
    {
        return static::liveRows()->where('v.hls_video_id', $videoId)->exists();
    }

    /** The lessons (or other videoables) still using the video, trashed included. */
    public static function liveModels(string $videoId, int $limit = 10): Collection
    {
        return static::liveRows()
            ->where('v.hls_video_id', $videoId)
            ->limit($limit)
            ->get(['v.videoable_type', 'v.videoable_id'])
            ->map(function ($row) {
                $class = Model::getActualClassNameForMorph($row->videoable_type);
                if (! class_exists($class)) {
                    return null;
                }
                $query = $class::query();
                if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                    $query->withTrashed();
                }

                return $query->find($row->videoable_id);
            })
            ->filter()
            ->values();
    }

    /** hls_videoables rows (aliased `v`) whose model still exists. */
    public static function liveRows(): Builder
    {
        return static::rows(true);
    }

    /** hls_videoables rows (aliased `v`) whose model is gone. */
    public static function deadRows(): Builder
    {
        return static::rows(false);
    }

    private static function rows(bool $live): Builder
    {
        $query = DB::table('hls_videoables as v');
        $tables = static::tables();

        if (! $tables) {
            // No known type: every row counts as live.
            return $live ? $query : $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($tables, $live) {
            foreach ($tables as $type => [$table, $key]) {
                $exists = fn (Builder $sub) => $sub->selectRaw(1)->from($table)->whereColumn("{$table}.{$key}", 'v.videoable_id');
                $q->orWhere(function (Builder $row) use ($type, $exists, $live) {
                    $row->where('v.videoable_type', $type);
                    $live ? $row->whereExists($exists) : $row->whereNotExists($exists);
                });
            }
            if ($live) {
                $q->orWhereNotIn('v.videoable_type', array_keys($tables));
            }
        });
    }

    /** [videoable_type => [table, key]] for every type in hls_videoables whose class exists. */
    private static function tables(): array
    {
        $tables = [];
        foreach (DB::table('hls_videoables')->distinct()->pluck('videoable_type') as $type) {
            $class = Model::getActualClassNameForMorph($type);
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            $model = new $class;
            $tables[$type] = [$model->getTable(), $model->getKeyName()];
        }

        return $tables;
    }
}
