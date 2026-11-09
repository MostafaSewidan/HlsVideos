<?php

namespace HlsVideos\Archive;

/**
 * Settings of the archive engine, built from config('hls-videos.archive').
 * Plain object so the engine runs and is tested without Laravel.
 */
class ArchiveConfig
{
    public function __construct(
        public string $workDir,
        public string $boxRemote,
        public string $boxName = 'default',
        public string $rcloneBinary = 'rclone',
        public ?string $rcloneConfig = null,
        public array $rcloneFlags = ['--retries', '3', '--low-level-retries', '10'],
        // The Storage Box allows 10 connections per account, and going over can get
        // the IP blocked for a while. One connection per rclone process stays far from it.
        public array $boxFlags = ['--sftp-connections', '1', '--transfers', '1', '--checkers', '1'],
        public int $r2Transfers = 16,
        public ?string $boxSshHost = null,
        public int $boxSshPort = 23,
        public ?string $boxSshKey = null,
        public ?string $boxSshBase = null,
        // auto: sha256 over ssh, falling back to reading the file back. ssh | download: only that.
        public string $verifyMode = 'auto',
        public int $processTimeout = 7200,
        public bool $keepWork = false,
    ) {
        if (! in_array($verifyMode, ['auto', 'ssh', 'download'], true)) {
            throw new ArchiveException("Unknown verify mode '{$verifyMode}'");
        }
    }

    public static function fromArray(array $c): self
    {
        $box = $c['box'] ?? [];

        foreach (['work_dir' => $c['work_dir'] ?? null, 'box.remote' => $box['remote'] ?? null] as $key => $value) {
            if (empty($value)) {
                throw new ArchiveException("hls-videos.archive.{$key} is not set");
            }
        }

        return new self(
            workDir: rtrim($c['work_dir'], '/'),
            boxRemote: rtrim($box['remote'], '/'),
            boxName: $box['name'] ?? 'default',
            rcloneBinary: $c['rclone_binary'] ?? 'rclone',
            rcloneConfig: $c['rclone_config'] ?? null,
            rcloneFlags: $c['rclone_flags'] ?? ['--retries', '3', '--low-level-retries', '10'],
            boxFlags: ['--sftp-connections', '1', '--transfers', '1', '--checkers', '1'],
            r2Transfers: (int) ($c['r2_transfers'] ?? 16),
            boxSshHost: $box['ssh_host'] ?? null,
            boxSshPort: (int) ($box['ssh_port'] ?? 23),
            boxSshKey: $box['ssh_key'] ?? null,
            boxSshBase: $box['ssh_base'] ?? null,
            verifyMode: $box['verify'] ?? 'auto',
            processTimeout: (int) ($c['process_timeout'] ?? 7200),
            keepWork: (bool) ($c['keep_work'] ?? false),
        );
    }
}
