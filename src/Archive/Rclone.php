<?php

namespace HlsVideos\Archive;

use Symfony\Component\Process\Process;

/**
 * Runs rclone. Remotes are rclone paths such as "r2src:steps/total" or a local
 * folder. Box commands get the box flags, which keep the Storage Box under its
 * connection limit. No command gets stdin.
 */
class Rclone
{
    public function __construct(private ArchiveConfig $config)
    {
    }

    public function run(array $args, bool $box = false): string
    {
        $process = new Process($this->command($args, $box));
        $process->setTimeout($this->config->processTimeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ArchiveException('rclone '.implode(' ', array_slice($args, 0, 3)).' failed: '
                .$this->tail($process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /** Files under $path: [{Path, Name, Size, ...}]. */
    public function lsjson(string $path, bool $recursive = true, bool $box = false): array
    {
        $args = ['lsjson', '--files-only', '--no-modtime', '--no-mimetype'];
        if ($recursive) {
            $args[] = '-R';
        }
        $args[] = $path;

        return $this->decode($this->run($args, $box), $path);
    }

    /** One object, with its MimeType (the Content-Type it is stored with). */
    public function stat(string $path): array
    {
        $item = $this->decode($this->run(['lsjson', '--stat', $path]), $path);
        if (! isset($item['Size'])) {
            throw new ArchiveException("Not found: {$path}");
        }

        return $item;
    }

    /** Copies the files named in $listFile (one relative path per line) from $from to $to. */
    public function copyFiles(string $from, string $to, string $listFile, array $extra = []): void
    {
        $this->run(array_merge(
            ['copy', $from, $to, '--files-from-raw', $listFile, '--no-traverse', '--transfers', (string) $this->config->r2Transfers],
            $extra
        ));
    }

    public function copyTo(string $src, string $dst, array $extra = [], bool $box = false): void
    {
        $this->run(array_merge(['copyto', $src, $dst], $extra), $box);
    }

    /** One-way check: every file in the list exists in $dst with the same content hash. */
    public function check(string $src, string $dst, string $listFile): void
    {
        $this->run(['check', $src, $dst, '--one-way', '--files-from-raw', $listFile]);
    }

    public function deleteFiles(string $base, string $listFile): void
    {
        $this->run(['delete', $base, '--files-from-raw', $listFile]);
    }

    public function cat(string $path, bool $box = false, ?int $offset = null, ?int $count = null): string
    {
        return $this->run($this->catArgs($path, $offset, $count), $box);
    }

    /** sha256 of a file (or a byte range of it) read back through rclone, streamed. */
    public function sha256(string $path, bool $box = false, ?int $offset = null, ?int $count = null): string
    {
        $pipes = [];
        $proc = proc_open($this->command($this->catArgs($path, $offset, $count), $box), [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (! is_resource($proc)) {
            throw new ArchiveException("Could not start rclone for {$path}");
        }

        $hash = hash_init('sha256');
        stream_set_blocking($pipes[2], false);
        $stderr = '';
        while (! feof($pipes[1])) {
            $chunk = fread($pipes[1], 1 << 20);
            if ($chunk === false) {
                break;
            }
            hash_update($hash, $chunk);
            $stderr .= (string) stream_get_contents($pipes[2]);
        }
        fclose($pipes[1]);
        stream_set_blocking($pipes[2], true);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        if (proc_close($proc) !== 0) {
            throw new ArchiveException("Reading {$path} failed: ".$this->tail($stderr));
        }

        return hash_final($hash);
    }

    private function catArgs(string $path, ?int $offset, ?int $count): array
    {
        $args = ['cat', $path];
        if ($offset !== null) {
            array_push($args, '--offset', (string) $offset, '--count', (string) $count);
        }

        return $args;
    }

    private function command(array $args, bool $box): array
    {
        $cmd = [$this->config->rcloneBinary];
        if ($this->config->rcloneConfig) {
            array_push($cmd, '--config', $this->config->rcloneConfig);
        }

        return array_merge($cmd, $this->config->rcloneFlags, $box ? $this->config->boxFlags : [], $args);
    }

    private function decode(string $json, string $path): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new ArchiveException("Unreadable rclone listing for {$path}");
        }

        return $data;
    }

    private function tail(string $text): string
    {
        $lines = array_slice(array_filter(explode("\n", trim($text))), -5);

        return implode(' | ', $lines) ?: 'no error output';
    }
}
