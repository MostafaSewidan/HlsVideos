<?php

namespace HlsVideos\Archive;

/**
 * Uncompressed ustar archives, readable by GNU and BSD tar.
 *
 * write() records where each file's data starts in the tar (offset) and its
 * sha256, so one file can later be read straight from the tar by byte range,
 * and extract() works from those offsets without parsing the tar.
 */
class Tar
{
    private const BLOCK = 512;

    private const CHUNK = 1 << 20;

    // Plain relative names only, so extracting can never write outside the target.
    public const NAME_PATTERN = '#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)?$#';

    /**
     * Writes $names (relative to $dir, in this order) into $tarPath.
     *
     * @return array{sha256: string, size: int, files: array<int, array{name: string, size: int, offset: int, sha256: string}>}
     */
    public static function write(string $tarPath, string $dir, array $names): array
    {
        $out = fopen($tarPath, 'wb');
        if ($out === false) {
            throw new ArchiveException("Cannot write {$tarPath}");
        }

        $tarHash = hash_init('sha256');
        $pos = 0;
        $files = [];
        $put = function (string $bytes) use ($out, $tarHash, &$pos, $tarPath) {
            if (fwrite($out, $bytes) !== strlen($bytes)) {
                throw new ArchiveException("Writing {$tarPath} failed");
            }
            hash_update($tarHash, $bytes);
            $pos += strlen($bytes);
        };

        try {
            foreach ($names as $name) {
                self::checkName($name);
                $path = $dir.'/'.$name;
                clearstatcache(true, $path);
                if (! is_file($path)) {
                    throw new ArchiveException("Missing file for the tar: {$name}");
                }
                $size = filesize($path);

                $put(self::header($name, $size));
                $offset = $pos;

                $in = fopen($path, 'rb');
                $fileHash = hash_init('sha256');
                $left = $size;
                while ($left > 0) {
                    $chunk = fread($in, min($left, self::CHUNK));
                    if ($chunk === false || $chunk === '') {
                        fclose($in);
                        throw new ArchiveException("{$name} ended early while writing the tar");
                    }
                    hash_update($fileHash, $chunk);
                    $put($chunk);
                    $left -= strlen($chunk);
                }
                fclose($in);

                $pad = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
                if ($pad) {
                    $put(str_repeat("\0", $pad));
                }

                $files[] = ['name' => $name, 'size' => $size, 'offset' => $offset, 'sha256' => hash_final($fileHash)];
            }

            $put(str_repeat("\0", self::BLOCK * 2));
        } finally {
            fclose($out);
        }

        return ['sha256' => hash_final($tarHash), 'size' => $pos, 'files' => $files];
    }

    /**
     * Reads the tar back and checks it against what write() returned: the whole
     * tar's sha256, each file's bytes at its offset, and the header in front of it.
     */
    public static function verify(string $tarPath, array $written): void
    {
        clearstatcache(true, $tarPath);
        if (filesize($tarPath) !== $written['size']) {
            throw new ArchiveException("{$tarPath}: size on disk differs from what was written");
        }
        if (hash_file('sha256', $tarPath) !== $written['sha256']) {
            throw new ArchiveException("{$tarPath}: sha256 on disk differs from what was written");
        }

        $in = fopen($tarPath, 'rb');
        try {
            foreach ($written['files'] as $file) {
                fseek($in, $file['offset'] - self::BLOCK);
                $header = fread($in, self::BLOCK);
                if (rtrim(substr($header, 0, 100), "\0") !== $file['name']) {
                    throw new ArchiveException("{$tarPath}: header in front of offset {$file['offset']} is not {$file['name']}");
                }
                if (self::shaRange($in, $file['offset'], $file['size']) !== $file['sha256']) {
                    throw new ArchiveException("{$tarPath}: {$file['name']} at offset {$file['offset']} doesn't match");
                }
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * Writes every file of $files from the tar into $dir, using the offsets, and
     * checks each one's size and sha256. Files must not overlap and must sit
     * inside the tar.
     */
    public static function extract(string $tarPath, array $files, string $dir): void
    {
        clearstatcache(true, $tarPath);
        $tarSize = filesize($tarPath);
        $in = fopen($tarPath, 'rb');
        $end = 0;

        try {
            foreach ($files as $file) {
                $name = $file['name'];
                self::checkName($name);
                $offset = (int) $file['offset'];
                $size = (int) $file['size'];
                if ($offset < $end + self::BLOCK || $offset % self::BLOCK !== 0 || $offset + $size > $tarSize) {
                    throw new ArchiveException("{$name}: offset {$offset} is not valid in this tar");
                }
                $end = $offset + $size;

                $target = $dir.'/'.$name;
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                $out = fopen($target, 'wb');
                $hash = hash_init('sha256');
                fseek($in, $offset);
                $left = $size;
                while ($left > 0) {
                    $chunk = fread($in, min($left, self::CHUNK));
                    if ($chunk === false || $chunk === '') {
                        fclose($out);
                        throw new ArchiveException("{$name}: tar ended early");
                    }
                    hash_update($hash, $chunk);
                    fwrite($out, $chunk);
                    $left -= strlen($chunk);
                }
                fclose($out);

                if (hash_final($hash) !== $file['sha256']) {
                    throw new ArchiveException("{$name}: sha256 doesn't match the manifest");
                }
            }
        } finally {
            fclose($in);
        }
    }

    public static function checkName(string $name): void
    {
        if (strlen($name) > 100 || ! preg_match(self::NAME_PATTERN, $name) || preg_match('#(^|/)\.\.?(/|$)#', $name)) {
            throw new ArchiveException("Unexpected file name '{$name}'");
        }
    }

    private static function header(string $name, int $size): string
    {
        if ($size > 077777777777) {
            throw new ArchiveException("{$name} is too large for a ustar entry");
        }

        $h = str_pad($name, 100, "\0")
            .sprintf('%07o', 0644)."\0"
            .sprintf('%07o', 0)."\0"
            .sprintf('%07o', 0)."\0"
            .sprintf('%011o', $size)."\0"
            .sprintf('%011o', 0)."\0"   // mtime 0: the same files always give the same tar
            .'        '                  // checksum, filled in below
            .'0'
            .str_repeat("\0", 100)
            ."ustar\0".'00'
            .str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);

        $sum = array_sum(array_map('ord', str_split($h)));

        return substr_replace($h, sprintf('%06o', $sum)."\0 ", 148, 8);
    }

    private static function shaRange($in, int $offset, int $size): string
    {
        fseek($in, $offset);
        $hash = hash_init('sha256');
        $left = $size;
        while ($left > 0) {
            $chunk = fread($in, min($left, self::CHUNK));
            if ($chunk === false || $chunk === '') {
                throw new ArchiveException('tar ended early');
            }
            hash_update($hash, $chunk);
            $left -= strlen($chunk);
        }

        return hash_final($hash);
    }
}
