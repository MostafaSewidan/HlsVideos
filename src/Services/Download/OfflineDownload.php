<?php

namespace HlsVideos\Services\Download;

use HlsVideos\Exceptions\DownloadUnavailableException;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Repositories\HlsVideoExportRepository;
use Illuminate\Support\Facades\Log;

/**
 * Offline download of one video, for the app API. The caller (base_backend)
 * checks who may download; this decides what can be downloaded and builds the
 * response: which qualities, the two signed links, the playlist, the key.
 *
 * Plan: base_backend docs/hls-offline-download-plan.md. Contract for the app:
 * base_backend docs/hls-offline-download-api.md.
 */
class OfflineDownload
{
    public function __construct(
        private DownloadSource $source,
        private OfflinePlaylistBuilder $playlists,
        private DownloadUrlSigner $signer,
        private HlsVideoExportRepository $videos,
    ) {
    }

    public static function enabled(): bool
    {
        return (bool) config('hls-videos.offline_download.enabled', false);
    }

    /**
     * Qualities that can be downloaded now, highest first. A quality whose files
     * are missing is left out (and logged); a video that can't be downloaded at
     * all throws.
     *
     * @return array<int, array{quality: string, size: int, duration: float}>
     *
     * @throws DownloadUnavailableException
     */
    public function options(HlsVideo $video): array
    {
        $options = [];
        foreach ($this->videos->qualities($video) as $quality) {
            try {
                $source = $this->source->for($video, (string) $quality);
            } catch (DownloadUnavailableException $e) {
                if (! in_array($e->reason, [DownloadUnavailableException::NOT_FOUND, DownloadUnavailableException::BROKEN], true)) {
                    throw $e;
                }
                if ($e->reason === DownloadUnavailableException::BROKEN) {
                    Log::warning("[offline-download] {$e->getMessage()}");
                }

                continue;
            }
            $options[] = ['quality' => $source->quality, 'size' => $source->totalSize, 'duration' => $source->duration];
        }

        if (! $options) {
            throw new DownloadUnavailableException(DownloadUnavailableException::NOT_FOUND, "Video {$video->id} has no downloadable quality");
        }

        return $options;
    }

    /**
     * Everything the app needs to download one quality. $user goes into the
     * signed links and the Worker's logs (the student id).
     *
     * @throws DownloadUnavailableException
     */
    public function start(HlsVideo $video, string $quality, string $user): array
    {
        $source = $this->source->for($video, $quality);
        $urls = $this->signer->urls($source, $user);

        return [
            'video_id' => $video->id,
            'quality' => $source->quality,
            'version' => $source->version,
            'file' => [
                'name' => "{$source->quality}.ts",
                'size' => $source->totalSize,
                'url' => $urls['url'],
                'backup_url' => $urls['backup_url'],
                'expires_at' => $urls['expires_at'],
            ],
            'playlist' => [
                'name' => "{$source->quality}.m3u8",
                'content' => $this->playlists->build($source),
            ],
        ];
    }
}
