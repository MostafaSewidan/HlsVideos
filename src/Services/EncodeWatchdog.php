<?php

namespace HlsVideos\Services;

/**
 * Runs scripts/encode-watchdog.sh beside an ffmpeg encode.
 *
 * ffmpeg sometimes freezes mid-encode (seen in busy hours: a video that
 * normally encodes in 10 minutes sat in encode until the 2-hour worker
 * timeout). laravel-ffmpeg only reacts when ffmpeg prints something, and the
 * worker already owns SIGALRM, so the check runs as a separate process that
 * watches the segment files instead.
 */
class EncodeWatchdog
{
    protected ?int $pid = null;

    public function __construct(protected string $outputDir, protected int $stallSeconds)
    {
    }

    public static function forDir(string $outputDir): self
    {
        return new self($outputDir, (int) config('hls-videos.encode_stall_minutes', 10) * 60);
    }

    public function start(): void
    {
        $this->clearMarker();

        if ($this->stallSeconds <= 0) {
            return;
        }

        $cmd = 'nohup bash '.escapeshellarg(__DIR__.'/scripts/encode-watchdog.sh')
            .' '.escapeshellarg($this->outputDir)
            .' '.(int) $this->stallSeconds
            .' '.getmypid()
            .' > /dev/null 2>&1 & echo $!';

        $pid = (int) trim((string) shell_exec($cmd));
        $this->pid = $pid > 0 ? $pid : null;
    }

    public function stop(): void
    {
        if ($this->pid) {
            exec('kill '.$this->pid.' > /dev/null 2>&1');
            $this->pid = null;
        }
    }

    /** What the watchdog wrote when it killed ffmpeg, or null if it did not. */
    public function stallReason(): ?string
    {
        $marker = $this->markerPath();

        return is_file($marker) ? trim((string) file_get_contents($marker)) : null;
    }

    public function clearMarker(): void
    {
        @unlink($this->markerPath());
    }

    protected function markerPath(): string
    {
        return rtrim($this->outputDir, '/').'/.encode-stalled';
    }
}
