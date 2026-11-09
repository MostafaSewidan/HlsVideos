<?php

namespace HlsVideos\Tests\Archive;

trait TempDirs
{
    private array $tempDirs = [];

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/hls-archive-test-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            exec('rm -rf '.escapeshellarg($dir));
        }
        parent::tearDown();
    }
}
