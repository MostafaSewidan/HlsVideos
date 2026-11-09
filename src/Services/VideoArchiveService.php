<?php

namespace HlsVideos\Services;

use HlsVideos\Archive\ArchiveConfig;
use HlsVideos\Archive\ArchiveException;
use HlsVideos\Archive\BrokenVideoException;
use HlsVideos\Archive\VideoArchiver;
use HlsVideos\Archive\VideoListing;
use HlsVideos\Models\HlsVideo;
use Illuminate\Support\Facades\Log;

/**
 * archive() and unArchive() of one video in the current tenant: the storage_state
 * changes around the archive engine (VideoArchiver), each one a compare-and-set,
 * so two runs can never work on the same video.
 *
 * Nothing calls this automatically yet. Segments are deleted from R2 only when
 * hls-videos.archive.delete_enabled is on; until then archive() leaves the video
 * hot, with a verified copy on the box.
 */
class VideoArchiveService
{
    private ?VideoArchiver $archiver;

    /** @var callable|null */
    private $output = null;

    public function __construct(?VideoArchiver $archiver = null)
    {
        $this->archiver = $archiver;
    }

    /** Also sends the engine's log lines here (e.g. a console command's output). */
    public function setOutput(?callable $output): self
    {
        $this->output = $output;

        return $this;
    }

    public function inspect(HlsVideo $video): VideoListing
    {
        return $this->archiver()->inspect($this->sourceRemote($video), $this->mediaFolder(), $video->id);
    }

    /**
     * Archives the video's files to the box and verifies them. With deleting on,
     * marks it archived and deletes its segments from R2 (playlists and thumbnail stay).
     *
     * $stillArchivable runs right before the delete: returning false (say the lesson
     * was just activated) leaves the video hot with nothing deleted.
     */
    public function archive(HlsVideo $video, ?callable $stillArchivable = null): HlsVideo
    {
        if ($video->status !== HlsVideo::READY) {
            throw new ArchiveException("Video {$video->id} is not ready ({$video->status})");
        }
        $source = $this->sourceRemote($video);
        $this->transition($video, HlsVideo::STORAGE_HOT, HlsVideo::STORAGE_ARCHIVING);

        try {
            $manifest = $this->archiver()->archive($source, $this->mediaFolder(), $video->id);
            $video->forceFill([
                'archive_box' => config('hls-videos.archive.box.name', 'default'),
                'archive_path' => $this->mediaFolder().'/'.$video->id,
                'archive_manifest' => VideoArchiver::summary($manifest),
                'hot_size_bytes' => $manifest['total_bytes'],
                'archived_at' => now(),
            ])->save();
        } catch (BrokenVideoException $e) {
            $this->setState($video, HlsVideo::STORAGE_FAILED, $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->setState($video, HlsVideo::STORAGE_HOT, $e->getMessage());
            throw $e;
        }

        if (! config('hls-videos.archive.delete_enabled', false)) {
            $this->setState($video, HlsVideo::STORAGE_HOT, null);
            $this->log("deleting is off (hls-videos.archive.delete_enabled): {$video->id} stays hot, with a verified copy on the box");

            return $video->refresh();
        }

        if ($stillArchivable && ! $stillArchivable($video)) {
            $this->setState($video, HlsVideo::STORAGE_HOT, null);
            $this->log("{$video->id} is in use again: stays hot, nothing deleted");

            return $video->refresh();
        }

        // Archived first, then the delete: if the delete stops halfway, the video is
        // still known to be archived and unArchive() brings every segment back.
        $this->setState($video, HlsVideo::STORAGE_ARCHIVED, null);
        try {
            $this->archiver()->deleteSegments($source, $this->mediaFolder(), $video->id, $manifest);
        } catch (\Throwable $e) {
            $video->forceFill(['storage_error' => 'delete from R2 failed: '.$e->getMessage()])->save();
            throw $e;
        }

        return $video->refresh();
    }

    /**
     * Brings the archived video back to its bucket and marks it hot.
     *
     * With $testTarget (an rclone path such as "r2test:steps-archive-test") it only
     * restores a copy there and changes nothing on the video, which works whether or
     * not the segments were deleted.
     */
    public function unArchive(HlsVideo $video, ?string $testTarget = null): HlsVideo
    {
        if (! $video->archive_path) {
            throw new ArchiveException("Video {$video->id} has no archive");
        }

        if ($testTarget !== null) {
            $this->archiver()->restore($this->mediaFolder(), $video->id, $testTarget);

            return $video;
        }

        $target = $this->sourceRemote($video);
        $this->transition($video, HlsVideo::STORAGE_ARCHIVED, HlsVideo::STORAGE_RESTORING);

        try {
            $this->archiver()->restore($this->mediaFolder(), $video->id, $target);
        } catch (\Throwable $e) {
            $this->setState($video, HlsVideo::STORAGE_ARCHIVED, $e->getMessage());
            throw $e;
        }

        $video->forceFill(['restored_at' => now()])->save();
        $this->setState($video, HlsVideo::STORAGE_HOT, null);

        return $video->refresh();
    }

    /** The rclone path of the bucket the video plays from. */
    public function sourceRemote(HlsVideo $video): string
    {
        $bucket = $video->storage_bucket ?: HlsVideo::STORAGE_LEGACY;
        $remote = config("hls-videos.archive.sources.{$bucket}");
        if (! $remote) {
            throw new ArchiveException("No archive source configured for the '{$bucket}' bucket (hls-videos.archive.sources.{$bucket})");
        }

        return rtrim($remote, '/');
    }

    public function archiver(): VideoArchiver
    {
        if (! $this->archiver) {
            $this->archiver = new VideoArchiver(ArchiveConfig::fromArray(config('hls-videos.archive', [])));
        }

        return $this->archiver->setLogger(fn (string $line) => $this->log($line));
    }

    private function mediaFolder(): string
    {
        return rtrim(VideoService::getMediaPath(), '/');
    }

    private function transition(HlsVideo $video, string $from, string $to): void
    {
        $changed = HlsVideo::whereKey($video->getKey())
            ->where('storage_state', $from)
            ->toBase()
            ->update(['storage_state' => $to, 'storage_error' => null]);

        if ($changed !== 1) {
            $current = HlsVideo::whereKey($video->getKey())->value('storage_state');
            throw new ArchiveException("Video {$video->id} is '{$current}', expected '{$from}'");
        }
        $video->storage_state = $to;
        $video->syncOriginalAttribute('storage_state');
    }

    private function setState(HlsVideo $video, string $state, ?string $error): void
    {
        HlsVideo::whereKey($video->getKey())->toBase()->update(['storage_state' => $state, 'storage_error' => $error]);
        $video->storage_state = $state;
        $video->storage_error = $error;
        $video->syncOriginalAttributes(['storage_state', 'storage_error']);
    }

    private function log(string $line): void
    {
        Log::info("[video-archive] {$line}");
        if ($this->output) {
            ($this->output)($line);
        }
    }
}
