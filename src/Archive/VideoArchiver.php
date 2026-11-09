<?php

namespace HlsVideos\Archive;

/**
 * Moves the files of one video between its bucket and the Storage Box. Knows
 * nothing about the database: it takes rclone paths and returns the manifest, so
 * the archive worker can run it directly.
 *
 * On the box, per video:
 *   {box}/{mf}/{id}/{quality}.tar   segments of one quality, in playback order, no compression
 *   {box}/{mf}/{id}/meta.tar        playlists, thumbnail and the rest (vd.zip and exports/ excluded)
 *   {box}/{mf}/{id}/manifest.json   per tar: size and sha256; per file: size, sha256, offset in the tar
 *
 * The format is the same as scripts/video-archive in base_backend, so either can
 * restore what the other archived.
 */
class VideoArchiver
{
    public const MANIFEST_VERSION = 3;

    /** @var callable */
    private $logger;

    public function __construct(
        private ArchiveConfig $config,
        private ?Rclone $rclone = null,
        ?callable $logger = null,
    ) {
        $this->rclone = $rclone ?? new Rclone($config);
        $this->logger = $logger ?? function (string $line) {
        };
    }

    public function setLogger(callable $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /** Lists the video on $source and checks every playlist's segments exist. Read only. */
    public function inspect(string $source, string $mf, string $id): VideoListing
    {
        $base = $this->videoPath($source, $mf, $id);
        $objects = $this->rclone->lsjson($base);
        if (! $objects) {
            throw new ArchiveException("Nothing found under {$base}");
        }

        return VideoListing::fromObjects($objects, fn (string $path) => $this->rclone->cat("{$base}/{$path}"));
    }

    /**
     * Archives the video from $source to the box and verifies it there. Never
     * writes to or deletes from $source. Returns the manifest.
     */
    public function archive(string $source, string $mf, string $id): array
    {
        $src = $this->videoPath($source, $mf, $id);
        $dest = $this->videoPath($this->config->boxRemote, $mf, $id);
        $this->guardNotInside($dest, $source);
        $dir = $this->workDir('archive', $mf, $id);
        $started = microtime(true);

        try {
            $this->log("archive {$src} -> {$dest}");
            $listing = $this->inspect($source, $mf, $id);
            foreach ($listing->warnings as $warning) {
                $this->log("WARNING: {$warning}");
            }
            if ($listing->isBroken()) {
                throw new BrokenVideoException($listing->missing);
            }

            $tars = [];
            foreach ($listing->segments as $quality => $files) {
                $quality = (string) $quality;
                $contentType = $this->segmentContentType($src, $quality, $files);
                $tar = $this->pack($quality, "{$src}/{$quality}", $files, $dir, $dest);
                $tars[] = ['name' => $quality, 'kind' => 'segments'] + $tar + ['content_type' => $contentType];
                $this->log("quality {$quality}: ".count($files).' segments, '.self::human($tar['size']).", {$contentType}");
            }

            if ($listing->meta) {
                $tar = $this->pack('meta', $src, $listing->meta, $dir, $dest);
                foreach ($tar['files'] as &$file) {
                    $file['content_type'] = $this->contentType("{$src}/{$file['name']}");
                }
                unset($file);
                $tars[] = ['name' => 'meta', 'kind' => 'meta'] + $tar;
                $this->log('meta: '.count($listing->meta).' files, '.self::human($tar['size']));
            }

            $manifest = [
                'version' => self::MANIFEST_VERSION,
                'media_folder' => $mf,
                'video_id' => $id,
                'source' => $source,
                'archived_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'tars' => $tars,
                'excluded' => array_map(fn ($f) => ['name' => $f[0], 'size' => $f[1]], $listing->excluded),
                'total_files' => array_sum(array_column($tars, 'file_count')),
                'total_bytes' => array_sum(array_map(fn ($t) => array_sum(array_column($t['files'], 'size')), $tars)),
            ];
            self::validateManifest($manifest);

            $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            file_put_contents("{$dir}/manifest.json", $json);
            $this->rclone->copyTo("{$dir}/manifest.json", "{$dest}/manifest.json", [], true);

            $this->verifyOnBox($dest, $manifest, hash('sha256', $json));
            $this->log("DONE {$mf}/{$id}: {$manifest['total_files']} files, ".self::human($manifest['total_bytes'])
                .' in '.round(microtime(true) - $started, 1).'s. Nothing was deleted from the source');

            return $manifest;
        } finally {
            $this->cleanup($dir);
        }
    }

    /**
     * Restores the archived video from the box to $target, at the same keys it had,
     * with the Content-Type each file had. Checks every file on the way down, and
     * the target afterwards. Returns the manifest.
     */
    public function restore(string $mf, string $id, string $target): array
    {
        $from = $this->videoPath($this->config->boxRemote, $mf, $id);
        $dest = $this->videoPath($target, $mf, $id);
        $dir = $this->workDir('restore', $mf, $id);
        $started = microtime(true);

        try {
            $this->log("restore {$from} -> {$dest}");
            $manifest = $this->readManifest($mf, $id, $dir);

            foreach ($manifest['tars'] as $tar) {
                $local = "{$dir}/{$tar['name']}.tar";
                $this->rclone->copyTo("{$from}/{$tar['tar']}", $local, [], true);
                if (hash_file('sha256', $local) !== $tar['sha256']) {
                    throw new ArchiveException("{$tar['tar']}: sha256 doesn't match the manifest");
                }

                $out = "{$dir}/out/{$tar['name']}";
                Tar::extract($local, $tar['files'], $out);
                $this->keepWork() || unlink($local);

                $list = "{$dir}/{$tar['name']}.list";
                file_put_contents($list, implode("\n", array_column($tar['files'], 'name'))."\n");

                // --ignore-times: always upload, so a copy already there gets the right metadata too.
                if ($tar['kind'] === 'segments') {
                    $to = "{$dest}/{$tar['name']}";
                    $this->rclone->copyFiles($out, $to, $list, ['--ignore-times', '--header-upload', 'Content-Type: '.$tar['content_type']]);
                } else {
                    $to = $dest;
                    foreach ($tar['files'] as $file) {
                        $this->rclone->copyTo("{$out}/{$file['name']}", "{$to}/{$file['name']}",
                            ['--ignore-times', '--header-upload', 'Content-Type: '.$file['content_type']]);
                    }
                }
                $this->rclone->check($out, $to, $list);
                $this->keepWork() || self::removeDir($out);
                $this->log("{$tar['name']}: {$tar['file_count']} files restored");
            }

            $this->verifyTarget($dest, $manifest);
            $this->log("DONE {$mf}/{$id}: {$manifest['total_files']} files restored to {$dest} in "
                .round(microtime(true) - $started, 1).'s');

            return $manifest;
        } finally {
            $this->cleanup($dir);
        }
    }

    /**
     * Deletes the video's segments from $source, and only the ones the manifest
     * holds. Playlists and the thumbnail stay. Refuses unless every one of those
     * segments is still on $source with the size that was archived.
     */
    public function deleteSegments(string $source, string $mf, string $id, array $manifest): int
    {
        self::validateManifest($manifest);
        if ($manifest['media_folder'] !== $mf || $manifest['video_id'] !== $id) {
            throw new ArchiveException('The manifest belongs to another video');
        }

        $src = $this->videoPath($source, $mf, $id);
        $expected = [];
        foreach ($manifest['tars'] as $tar) {
            if ($tar['kind'] === 'segments') {
                foreach ($tar['files'] as $file) {
                    $expected["{$tar['name']}/{$file['name']}"] = $file['size'];
                }
            }
        }
        if (! $expected) {
            return 0;
        }

        $current = [];
        foreach ($this->rclone->lsjson($src) as $o) {
            $current[$o['Path']] = (int) $o['Size'];
        }
        foreach ($expected as $path => $size) {
            if (($current[$path] ?? null) !== $size) {
                throw new ArchiveException("{$path} on the source differs from the archive, nothing deleted");
            }
        }

        $dir = $this->workDir('delete', $mf, $id);
        try {
            file_put_contents("{$dir}/delete.list", implode("\n", array_keys($expected))."\n");
            $this->rclone->deleteFiles($src, "{$dir}/delete.list");
            $this->log('deleted '.count($expected)." segments from {$src}");
        } finally {
            $this->cleanup($dir);
        }

        return count($expected);
    }

    public function readManifest(string $mf, string $id, ?string $dir = null): array
    {
        $own = $dir === null;
        $dir = $dir ?? $this->workDir('manifest', $mf, $id);
        try {
            $this->rclone->copyTo($this->videoPath($this->config->boxRemote, $mf, $id).'/manifest.json', "{$dir}/manifest.json", [], true);
            $manifest = json_decode((string) file_get_contents("{$dir}/manifest.json"), true);
            if (! is_array($manifest) || ($manifest['version'] ?? null) !== self::MANIFEST_VERSION) {
                throw new ArchiveException('Unknown manifest version on the box (archives made before offsets were recorded must be archived again)');
            }
            if (($manifest['media_folder'] ?? null) !== $mf || ($manifest['video_id'] ?? null) !== $id) {
                throw new ArchiveException('The manifest on the box belongs to another video');
            }
            self::validateManifest($manifest);

            return $manifest;
        } finally {
            $own && $this->cleanup($dir);
        }
    }

    /** The manifest without per-file lists: small enough to keep on the video row. */
    public static function summary(array $manifest): array
    {
        return [
            'version' => $manifest['version'],
            'archived_at' => $manifest['archived_at'],
            'source' => $manifest['source'],
            'total_files' => $manifest['total_files'],
            'total_bytes' => $manifest['total_bytes'],
            'tars' => array_map(fn ($t) => array_intersect_key($t, array_flip(['name', 'kind', 'tar', 'size', 'sha256', 'file_count', 'content_type'])), $manifest['tars']),
        ];
    }

    public static function validateManifest(array $m): void
    {
        foreach (['media_folder', 'video_id', 'tars', 'total_files', 'total_bytes'] as $key) {
            if (! isset($m[$key])) {
                throw new ArchiveException("Manifest without {$key}");
            }
        }
        $count = 0;
        foreach ($m['tars'] as $tar) {
            Tar::checkName($tar['name'] ?? '');
            if (($tar['tar'] ?? null) !== $tar['name'].'.tar' || ! in_array($tar['kind'] ?? null, ['segments', 'meta'], true)) {
                throw new ArchiveException("Manifest tar entry '{$tar['name']}' is malformed");
            }
            if ($tar['kind'] === 'segments' && empty($tar['content_type'])) {
                throw new ArchiveException("{$tar['name']}: no content_type in the manifest");
            }
            if (count($tar['files']) !== $tar['file_count']) {
                throw new ArchiveException("{$tar['name']}: file_count doesn't match the file list");
            }
            foreach ($tar['files'] as $file) {
                Tar::checkName($file['name'] ?? '');
                if (! isset($file['size'], $file['offset'], $file['sha256'])) {
                    throw new ArchiveException("{$tar['name']}/{$file['name']}: no size, offset or sha256 in the manifest");
                }
                if ($tar['kind'] === 'meta' && empty($file['content_type'])) {
                    throw new ArchiveException("{$file['name']}: no content_type in the manifest");
                }
            }
            $count += $tar['file_count'];
        }
        if ($count !== $m['total_files']) {
            throw new ArchiveException("Manifest total_files is {$m['total_files']}, the tars hold {$count}");
        }
    }

    /**
     * Downloads one group of files, checks them against the listing, writes the tar,
     * checks it, and uploads it to the box.
     */
    private function pack(string $name, string $from, array $files, string $dir, string $dest): array
    {
        $local = "{$dir}/files/{$name}";
        $list = "{$dir}/{$name}.list";
        $tarPath = "{$dir}/{$name}.tar";
        $names = array_column($files, 0);
        file_put_contents($list, implode("\n", $names)."\n");

        $this->rclone->copyFiles($from, $local, $list);
        foreach ($files as [$file, $size]) {
            clearstatcache(true, "{$local}/{$file}");
            if (! is_file("{$local}/{$file}") || filesize("{$local}/{$file}") !== $size) {
                throw new ArchiveException("{$name}: downloaded {$file} doesn't match the listing");
            }
        }

        $written = Tar::write($tarPath, $local, $names);
        Tar::verify($tarPath, $written);
        $this->keepWork() || self::removeDir($local);

        $this->rclone->copyTo($tarPath, "{$dest}/{$name}.tar", [], true);

        return [
            'tar' => "{$name}.tar",
            'size' => $written['size'],
            'sha256' => $written['sha256'],
            'file_count' => count($written['files']),
            'files' => $written['files'],
        ];
    }

    /**
     * Every tar is on the box with its size and sha256, the first and last file of
     * each read back by byte range match, and the manifest on the box is ours.
     */
    private function verifyOnBox(string $dest, array $manifest, string $manifestSha): void
    {
        $this->log('verifying on the box');
        $sizes = [];
        foreach ($this->rclone->lsjson($dest, false, true) as $o) {
            $sizes[$o['Name']] = (int) $o['Size'];
        }

        $verified = 0;
        foreach ($manifest['tars'] as $tar) {
            if (($sizes[$tar['tar']] ?? null) !== $tar['size']) {
                throw new ArchiveException("{$tar['tar']}: size on the box is ".($sizes[$tar['tar']] ?? 'missing').", expected {$tar['size']}");
            }
            [$sha, $method] = $this->boxSha256("{$manifest['media_folder']}/{$manifest['video_id']}/{$tar['tar']}");
            if ($sha !== $tar['sha256']) {
                throw new ArchiveException("{$tar['tar']}: sha256 on the box doesn't match ({$method})");
            }
            foreach (array_unique([0, count($tar['files']) - 1]) as $i) {
                $file = $tar['files'][$i];
                if ($this->rclone->sha256("{$dest}/{$tar['tar']}", true, $file['offset'], $file['size']) !== $file['sha256']) {
                    throw new ArchiveException("{$tar['tar']}: {$file['name']} read by range from the box doesn't match");
                }
            }
            $verified++;
            $this->log("ok {$tar['tar']}: ".self::human($tar['size']).", sha256 matches ({$method}), range reads match");
        }

        if ($verified !== count($manifest['tars'])) {
            throw new ArchiveException("Verified {$verified} of ".count($manifest['tars']).' tars');
        }
        if ($this->rclone->sha256("{$dest}/manifest.json", true) !== $manifestSha) {
            throw new ArchiveException('manifest.json differs on the box');
        }
    }

    /** The target holds exactly what the manifest lists (vd.zip and exports/ aside), with the same sizes. */
    private function verifyTarget(string $dest, array $manifest): void
    {
        $expected = [];
        foreach ($manifest['tars'] as $tar) {
            foreach ($tar['files'] as $file) {
                $expected[($tar['kind'] === 'segments' ? "{$tar['name']}/" : '').$file['name']] = $file['size'];
            }
        }

        $actual = [];
        foreach ($this->rclone->lsjson($dest) as $o) {
            if ($o['Path'] !== 'vd.zip' && ! str_starts_with($o['Path'], 'exports/')) {
                $actual[$o['Path']] = (int) $o['Size'];
            }
        }

        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            $diff = array_merge(
                array_map(fn ($p) => "missing or wrong size: {$p}", array_keys(array_diff_assoc($expected, $actual))),
                array_map(fn ($p) => "not in the manifest: {$p}", array_keys(array_diff_key($actual, $expected)))
            );
            throw new ArchiveException('Restored files don\'t match the manifest: '.implode(', ', array_slice($diff, 0, 10)));
        }
    }

    /** One Content-Type per quality, checked on its first, middle and last segment. */
    private function segmentContentType(string $src, string $quality, array $files): string
    {
        $n = count($files);
        $types = [];
        foreach (array_unique([0, intdiv($n - 1, 2), $n - 1]) as $i) {
            $types[] = $this->contentType("{$src}/{$quality}/{$files[$i][0]}");
        }
        $types = array_values(array_unique($types));
        if (count($types) !== 1) {
            throw new ArchiveException("Quality {$quality} mixes content types: ".implode(', ', $types));
        }

        return $types[0];
    }

    private function contentType(string $path): string
    {
        $type = $this->rclone->stat($path)['MimeType'] ?? '';
        if ($type === '') {
            throw new ArchiveException("No content type for {$path}");
        }

        return $type;
    }

    /** @return array{0: string, 1: string} sha256 and how it was computed */
    private function boxSha256(string $relative): array
    {
        $c = $this->config;
        if ($c->verifyMode !== 'download' && $c->boxSshHost) {
            $cmd = ['ssh', '-n', '-p', (string) $c->boxSshPort, '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15'];
            if ($c->boxSshKey) {
                array_push($cmd, '-i', $c->boxSshKey);
            }
            // Names are checked against Tar::NAME_PATTERN, so the path needs no quoting.
            array_push($cmd, $c->boxSshHost, 'sha256sum '.($c->boxSshBase ? rtrim($c->boxSshBase, '/').'/' : '').$relative);

            $process = new \Symfony\Component\Process\Process($cmd);
            $process->setTimeout(600);
            $process->run();
            $sum = strtok(trim($process->getOutput()), ' ');
            if ($process->isSuccessful() && is_string($sum) && preg_match('/^[0-9a-f]{64}$/', $sum)) {
                return [$sum, 'ssh'];
            }
            if ($c->verifyMode === 'ssh') {
                throw new ArchiveException("sha256sum over ssh failed for {$relative}");
            }
        }

        return [$this->rclone->sha256("{$c->boxRemote}/{$relative}", true), 'download'];
    }

    private function videoPath(string $remote, string $mf, string $id): string
    {
        Tar::checkName($mf);
        Tar::checkName($id);
        if (str_contains($mf, '/') || str_contains($id, '/')) {
            throw new ArchiveException("Invalid media folder or video id: {$mf}/{$id}");
        }

        return rtrim($remote, '/')."/{$mf}/{$id}";
    }

    private function guardNotInside(string $path, string $remote): void
    {
        $remote = rtrim($remote, '/');
        if ($path === $remote || str_starts_with($path, $remote.'/')) {
            throw new ArchiveException("Refusing to write to the source bucket: {$path}");
        }
    }

    private function workDir(string $kind, string $mf, string $id): string
    {
        $dir = "{$this->config->workDir}/{$kind}/{$mf}/{$id}";
        self::removeDir($dir);
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new ArchiveException("Cannot create {$dir}");
        }

        return $dir;
    }

    private function cleanup(string $dir): void
    {
        if ($this->keepWork()) {
            $this->log("work files kept in {$dir}");
        } else {
            self::removeDir($dir);
        }
    }

    private function keepWork(): bool
    {
        return $this->config->keepWork;
    }

    private function log(string $line): void
    {
        ($this->logger)($line);
    }

    private static function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < 4) {
            $value /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $value, $units[$i]);
    }
}
