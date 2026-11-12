<?php

namespace HlsVideos\Services\Download;

/**
 * One quality of one video, as the download Worker will stream it: the
 * segments of vd.m3u8 in play order, with their sizes on R2.
 */
class DownloadSourceData
{
    /**
     * @param  array<int, array{0: string, 1: int, 2: float}>  $segments  [name, size, duration]
     */
    public function __construct(
        public string $bucket,    // short bucket name in the download URL ("legacy")
        public string $prefix,    // "{media}/{video}/{quality}/"
        public string $quality,
        public string $version,
        public array $segments,
        public int $totalSize,
        public float $duration,
        public string $playlist,  // the original vd.m3u8
    ) {
    }

    public function segmentCount(): int
    {
        return count($this->segments);
    }
}
