<?php

namespace HlsVideos\Tests\Archive;

use HlsVideos\Archive\ArchiveConfig;
use HlsVideos\Archive\ArchiveException;
use HlsVideos\Archive\BrokenVideoException;
use HlsVideos\Archive\VideoArchiver;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real engine with rclone, using local folders as the bucket, the test
 * bucket and the box.
 */
class VideoArchiverTest extends TestCase
{
    use TempDirs;

    private const MF = 'tenant1';

    private const ID = '2566cb28-7a6a-4bfc-8da2-16e8c474afcd';

    private string $source;

    private string $box;

    private string $target;

    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        exec('command -v rclone', $_, $code);
        if ($code !== 0) {
            $this->markTestSkipped('rclone is not installed');
        }

        $this->source = $this->tempDir();
        $this->box = $this->tempDir();
        $this->target = $this->tempDir();
        $this->makeVideo();
    }

    public function test_archive_then_restore_gives_back_every_file(): void
    {
        $manifest = $this->archiver()->archive($this->source, self::MF, self::ID);

        $onBox = array_map('basename', glob("{$this->box}/".self::MF.'/'.self::ID.'/*'));
        sort($onBox);
        $this->assertSame(['360.tar', '720.tar', 'manifest.json', 'meta.tar'], $onBox);
        $this->assertSame(VideoArchiver::MANIFEST_VERSION, $manifest['version']);
        $this->assertSame(['360', '720', 'meta'], array_column($manifest['tars'], 'name'));
        $this->assertSame(['vd.zip', 'exports/abc/720.mp4'], array_column($manifest['excluded'], 'name'));
        $this->assertSame(10, $manifest['total_files']);
        $this->assertSame(['index-0.ts', 'index-1.ts', 'index-2.ts', 'index-10.ts'], array_column($manifest['tars'][1]['files'], 'name'));
        $this->assertStringContainsString('Nothing was deleted', implode("\n", $this->log));

        // The video on the source is untouched.
        $this->assertSame($this->sourceFiles(), $this->expectedSourceFiles);

        $this->archiver()->restore(self::MF, self::ID, $this->target);
        $restored = $this->files($this->target.'/'.self::MF.'/'.self::ID);
        $expected = $this->expectedSourceFiles;
        unset($expected['vd.zip'], $expected['exports/abc/720.mp4']);
        $this->assertSame($expected, $restored);
    }

    public function test_segments_can_be_read_from_the_tar_on_the_box_by_offset(): void
    {
        $manifest = $this->archiver()->archive($this->source, self::MF, self::ID);
        $tar = $manifest['tars'][1];
        $raw = file_get_contents("{$this->box}/".self::MF.'/'.self::ID.'/720.tar');

        foreach ($tar['files'] as $file) {
            $this->assertSame(
                file_get_contents("{$this->source}/".self::MF.'/'.self::ID."/720/{$file['name']}"),
                substr($raw, $file['offset'], $file['size'])
            );
        }
    }

    public function test_broken_video_is_never_archived(): void
    {
        unlink("{$this->source}/".self::MF.'/'.self::ID.'/720/index-2.ts');

        try {
            $this->archiver()->archive($this->source, self::MF, self::ID);
            $this->fail('a broken video was archived');
        } catch (BrokenVideoException $e) {
            $this->assertSame(['720/vd.m3u8 -> index-2.ts'], $e->missing);
        }
        $this->assertDirectoryDoesNotExist("{$this->box}/".self::MF);
    }

    public function test_empty_segment_counts_as_missing(): void
    {
        file_put_contents("{$this->source}/".self::MF.'/'.self::ID.'/360/index-1.ts', '');

        $this->expectException(BrokenVideoException::class);
        $this->archiver()->archive($this->source, self::MF, self::ID);
    }

    public function test_restore_fails_when_a_tar_changed_on_the_box(): void
    {
        $this->archiver()->archive($this->source, self::MF, self::ID);
        $tar = "{$this->box}/".self::MF.'/'.self::ID.'/720.tar';
        $h = fopen($tar, 'r+b');
        fseek($h, 600);
        fwrite($h, 'X');
        fclose($h);

        try {
            $this->archiver()->restore(self::MF, self::ID, $this->target);
            $this->fail('a changed tar was restored');
        } catch (ArchiveException $e) {
            $this->assertStringContainsString('720.tar: sha256', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->target.'/'.self::MF.'/'.self::ID.'/720');
    }

    public function test_refuses_a_box_inside_the_source_bucket(): void
    {
        $config = new ArchiveConfig(workDir: $this->tempDir(), boxRemote: "{$this->source}/archive", verifyMode: 'download');

        $this->expectExceptionMessage('Refusing to write to the source bucket');
        (new VideoArchiver($config))->archive($this->source, self::MF, self::ID);
    }

    public function test_delete_segments_removes_only_archived_segments(): void
    {
        $manifest = $this->archiver()->archive($this->source, self::MF, self::ID);

        $deleted = $this->archiver()->deleteSegments($this->source, self::MF, self::ID, $manifest);

        $this->assertSame(6, $deleted);
        $this->assertSame(
            ['360/vd.m3u8', '720/vd.m3u8', 'exports/abc/720.mp4', 'index.m3u8', 'thumb.jpg', 'vd.zip'],
            array_keys($this->sourceFiles())
        );
    }

    public function test_delete_segments_refuses_when_the_source_changed_after_archiving(): void
    {
        $manifest = $this->archiver()->archive($this->source, self::MF, self::ID);
        file_put_contents("{$this->source}/".self::MF.'/'.self::ID.'/720/index-1.ts', 'changed');

        try {
            $this->archiver()->deleteSegments($this->source, self::MF, self::ID, $manifest);
            $this->fail('segments were deleted although the source changed');
        } catch (ArchiveException $e) {
            $this->assertStringContainsString('nothing deleted', $e->getMessage());
        }
        $this->assertCount(10, array_filter(array_keys($this->sourceFiles()), fn ($p) => ! str_starts_with($p, 'exports/') && $p !== 'vd.zip'));
    }

    public function test_delete_segments_refuses_a_manifest_of_another_video(): void
    {
        $manifest = $this->archiver()->archive($this->source, self::MF, self::ID);
        $manifest['video_id'] = 'other';

        $this->expectExceptionMessage('another video');
        $this->archiver()->deleteSegments($this->source, self::MF, self::ID, $manifest);
    }

    public function test_restore_refuses_an_old_manifest_version(): void
    {
        $this->archiver()->archive($this->source, self::MF, self::ID);
        $path = "{$this->box}/".self::MF.'/'.self::ID.'/manifest.json';
        $m = json_decode(file_get_contents($path), true);
        $m['version'] = 2;
        file_put_contents($path, json_encode($m));

        $this->expectExceptionMessage('Unknown manifest version');
        $this->archiver()->restore(self::MF, self::ID, $this->target);
    }

    public function test_inspect_reads_old_absolute_segment_urls(): void
    {
        file_put_contents("{$this->source}/".self::MF.'/'.self::ID.'/360/vd.m3u8', implode("\n", [
            '#EXTM3U', '#EXT-X-KEY:METHOD=AES-128,URI="https://x.stepsio.com/secret.key"',
            '#EXTINF:4,', 'https://x.stepsio.com/api/vd/'.self::ID.'/stream/360/index-0.ts',
            '#EXTINF:4,', 'https://x.stepsio.com/api/vd/'.self::ID.'/stream/360/index-1.ts?v=1',
            '#EXT-X-ENDLIST', '',
        ]));

        $listing = $this->archiver()->inspect($this->source, self::MF, self::ID);

        $this->assertFalse($listing->isBroken());
    }

    private function archiver(): VideoArchiver
    {
        $config = new ArchiveConfig(workDir: $this->tempDir(), boxRemote: $this->box, verifyMode: 'download', r2Transfers: 4);

        return new VideoArchiver($config, null, function (string $line) {
            $this->log[] = $line;
        });
    }

    private array $expectedSourceFiles = [];

    private function makeVideo(): void
    {
        $base = "{$this->source}/".self::MF.'/'.self::ID;
        $put = function (string $path, string $bytes) use ($base) {
            @mkdir(dirname("{$base}/{$path}"), 0755, true);
            file_put_contents("{$base}/{$path}", $bytes);
        };

        foreach (['720' => [0, 1, 2, 10], '360' => [0, 1]] as $q => $segments) {
            $playlist = "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"secret.key\"\n";
            foreach ($segments as $n) {
                $put("{$q}/index-{$n}.ts", random_bytes(random_int(1000, 300000)));
                $playlist .= "#EXTINF:10,\nindex-{$n}.ts\n";
            }
            $put("{$q}/vd.m3u8", $playlist."#EXT-X-ENDLIST\n");
        }
        $put('index.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\n720/vd.m3u8\n");
        $put('thumb.jpg', random_bytes(5000));
        $put('vd.zip', random_bytes(100));
        $put('exports/abc/720.mp4', random_bytes(100));

        $this->expectedSourceFiles = $this->sourceFiles();
    }

    private function sourceFiles(): array
    {
        return $this->files("{$this->source}/".self::MF.'/'.self::ID);
    }

    /** relative path => sha256 */
    private function files(string $dir): array
    {
        $out = [];
        if (! is_dir($dir)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $out[substr($file->getPathname(), strlen($dir) + 1)] = hash_file('sha256', $file->getPathname());
        }
        ksort($out, SORT_STRING);

        return $out;
    }
}
