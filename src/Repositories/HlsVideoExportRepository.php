<?php

namespace HlsVideos\Repositories;

use HlsVideos\Models\HlsVideo;
use HlsVideos\Models\HlsVideoExport;
use HlsVideos\Models\HlsFolderVideo;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

class HlsVideoExportRepository
{
    public function findReadyVideo(string $videoId): ?HlsVideo
    {
        return HlsVideo::ready()->find($videoId);
    }

    /**
     * Qualities of a ready video, highest first ('720', '480', ...). Not
     * filtered by the quality's own status: older encoders never set it, and
     * a READY video has all of its qualities on the stream disk.
     */
    public function qualities(HlsVideo $video): Collection
    {
        return $video->qualities()
            ->pluck('quality')
            ->unique()
            ->sortByDesc(fn ($quality) => (int) $quality)
            ->values();
    }

    /**
     * The video's exports on $disk, keyed by quality.
     */
    public function exportsByQuality(HlsVideo $video, string $disk): Collection
    {
        return HlsVideoExport::where('hls_video_id', $video->id)
            ->where('disk', $disk)
            ->get()
            ->keyBy('quality');
    }

    public function firstOrCreate(HlsVideo $video, string $quality, string $disk): HlsVideoExport
    {
        return HlsVideoExport::firstOrCreate(
            ['hls_video_id' => $video->id, 'quality' => $quality, 'disk' => $disk],
            ['status' => HlsVideoExport::PENDING]
        );
    }

    /**
     * Moves a failed, expired or stale export back to pending. A single
     * conditional UPDATE, so of two concurrent requests only one wins and
     * dispatches the job.
     */
    public function claimForDispatch(HlsVideoExport $export): bool
    {
        $staleBefore = now()->subMinutes(config('hls-videos.exports.stale_after_minutes', 120));

        return HlsVideoExport::whereKey($export->id)
            ->where(function ($query) use ($staleBefore) {
                $query->whereIn('status', [HlsVideoExport::FAILED, HlsVideoExport::EXPIRED])
                    ->orWhere(function ($query) use ($staleBefore) {
                        $query->whereIn('status', [HlsVideoExport::PENDING, HlsVideoExport::PROCESSING])
                            ->where('updated_at', '<', $staleBefore);
                    });
            })
            ->update(['status' => HlsVideoExport::PENDING, 'error' => null, 'updated_at' => now()]) > 0;
    }

    public function extend(HlsVideoExport $export, $expiresAt): void
    {
        $export->update(['expires_at' => $expiresAt]);
    }

    public function markProcessing(HlsVideoExport $export): void
    {
        $export->update(['status' => HlsVideoExport::PROCESSING, 'error' => null]);
    }

    public function markReady(HlsVideoExport $export, string $path, int $size, $expiresAt): void
    {
        $export->update([
            'status' => HlsVideoExport::READY,
            'path' => $path,
            'size' => $size,
            'error' => null,
            'expires_at' => $expiresAt,
        ]);
    }

    public function markFailed(HlsVideoExport $export, string $error): void
    {
        $export->update(['status' => HlsVideoExport::FAILED, 'error' => Str::limit($error, 2000)]);
    }

    public function markExpired(HlsVideoExport $export): void
    {
        $export->update(['status' => HlsVideoExport::EXPIRED, 'path' => null, 'size' => null]);
    }

    /**
     * Name the video is shown under in the library (the entry $folderVideoId
     * when given, since one video can sit in several folders under different
     * names), else its uploaded file name.
     */
    public function displayTitle(HlsVideo $video, ?int $folderVideoId = null): string
    {
        $title = HlsFolderVideo::withoutGlobalScopes()
            ->where('hls_video_id', $video->id)
            ->whereNotNull('title')
            ->when($folderVideoId, fn ($query) => $query->orderByRaw('id = ? desc', [$folderVideoId]))
            ->value('title');

        return $title ?: pathinfo((string) $video->original_file_name, PATHINFO_FILENAME) ?: $video->id;
    }

    /**
     * Ready rows whose time is up, oldest first.
     */
    public function expired(): Collection
    {
        return HlsVideoExport::where('status', HlsVideoExport::READY)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('expires_at')
            ->get();
    }

    public function find(int $id): ?HlsVideoExport
    {
        return HlsVideoExport::find($id);
    }
}
