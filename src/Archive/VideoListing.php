<?php

namespace HlsVideos\Archive;

/**
 * The files of one video on its bucket, split the way the archive stores them:
 *   segments  quality => [[name, size], ...]  in playback order
 *   meta      [[path, size], ...]             playlists, thumbnail and anything else
 *   excluded  [[path, size], ...]             vd.zip and exports/, never archived
 *   missing   ["720/vd.m3u8 -> index-12.ts"]   segments a playlist points at that are missing or empty
 */
class VideoListing
{
    public function __construct(
        public array $segments,
        public array $meta,
        public array $excluded,
        public array $missing,
        public array $warnings,
    ) {
    }

    public static function fromObjects(array $objects, callable $readPlaylist): self
    {
        $segments = [];
        $meta = [];
        $excluded = [];
        $warnings = [];

        foreach ($objects as $o) {
            $path = $o['Path'];
            $size = (int) $o['Size'];

            if ($path === 'vd.zip' || str_starts_with($path, 'exports/')) {
                $excluded[] = [$path, $size];
            } elseif (preg_match('#^([^/]+)/([^/]+\.ts)$#', $path, $m)) {
                Tar::checkName($path);
                $segments[$m[1]][] = [$m[2], $size];
            } else {
                Tar::checkName($path);
                $meta[] = [$path, $size];
            }
        }

        if (! $segments && ! $meta) {
            throw new ArchiveException('The video has no files to archive');
        }

        uksort($segments, 'strnatcmp');
        foreach ($segments as &$files) {
            usort($files, fn ($a, $b) => strnatcmp($a[0], $b[0]));
        }
        unset($files);
        usort($meta, fn ($a, $b) => strnatcmp($a[0], $b[0]));

        // Every segment a media playlist points at must exist, with a size above zero.
        // Playlists name segments by file name or by an old absolute URL; both reduce
        // to a basename in the playlist's own folder.
        $missing = [];
        foreach ($meta as [$path]) {
            if (! preg_match('#^([^/]+)/[^/]+\.m3u8$#', $path, $m)) {
                continue;
            }
            $quality = $m[1];
            $playlist = str_replace("\r", '', $readPlaylist($path));
            if (! str_contains($playlist, '#EXTINF')) {
                continue;
            }

            $present = [];
            foreach ($segments[$quality] ?? [] as [$name, $size]) {
                if ($size > 0) {
                    $present[$name] = true;
                }
            }
            foreach (explode("\n", $playlist) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $name = basename(preg_replace('/[?#].*$/', '', $line));
                if (! isset($present[$name])) {
                    $missing[] = "{$path} -> {$name}";
                }
            }
        }

        $metaPaths = array_column($meta, 0);
        foreach (array_keys($segments) as $quality) {
            if (! in_array("{$quality}/vd.m3u8", $metaPaths, true)) {
                $warnings[] = "{$quality} has segments but no vd.m3u8";
            }
        }

        return new self($segments, $meta, $excluded, $missing, $warnings);
    }

    public function isBroken(): bool
    {
        return $this->missing !== [];
    }

    public function summary(): array
    {
        $rows = [];
        foreach ($this->segments as $quality => $files) {
            $rows[] = ['group' => (string) $quality, 'files' => count($files), 'bytes' => array_sum(array_column($files, 1))];
        }
        $rows[] = ['group' => 'meta', 'files' => count($this->meta), 'bytes' => array_sum(array_column($this->meta, 1))];
        $rows[] = ['group' => 'excluded', 'files' => count($this->excluded), 'bytes' => array_sum(array_column($this->excluded, 1))];

        return $rows;
    }
}
