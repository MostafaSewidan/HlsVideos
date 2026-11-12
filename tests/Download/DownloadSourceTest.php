<?php

namespace HlsVideos\Tests\Download;

use HlsVideos\Exceptions\DownloadUnavailableException;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Providers\HlsVideoServiceProvider;
use HlsVideos\Services\Download\DownloadSource;
use HlsVideos\Tests\Archive\TempDirs;
use Orchestra\Testbench\TestCase;

/**
 * DownloadSource against a local disk laid out like R2:
 * {media}/{video}/{quality}/vd.m3u8 + segments.
 */
class DownloadSourceTest extends TestCase
{
    use TempDirs;

    private string $root;

    protected function getPackageProviders($app)
    {
        return [HlsVideoServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $this->root = $this->tempDir();
        $app['config']->set('filesystems.disks.download_test', ['driver' => 'local', 'root' => $this->root]);
        $app['config']->set('hls-videos.stream_disk', 'download_test');
        $app->instance('currentTenant', (object) ['media_folder' => 'total']);
    }

    private function video(array $attributes = []): HlsVideo
    {
        return (new HlsVideo)->forceFill($attributes + [
            'id' => 'ca63ba0c-uuid',
            'status' => HlsVideo::READY,
            'storage_state' => HlsVideo::STORAGE_HOT,
            'storage_bucket' => HlsVideo::STORAGE_LEGACY,
        ]);
    }

    private function putFile(string $path, string $content): void
    {
        $file = "{$this->root}/{$path}";
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, $content);
    }

    private function playlist(array $uris): string
    {
        $out = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-KEY:METHOD=AES-128,URI=\"secret.key\",IV=0x01\n";
        foreach ($uris as $i => $uri) {
            $out .= sprintf("#EXTINF:%.6f,\n%s\n", 10 + $i / 10, $uri);
        }

        return $out."#EXT-X-ENDLIST\n";
    }

    public function test_order_from_the_playlist_sizes_from_the_listing(): void
    {
        $dir = 'total/ca63ba0c-uuid/720';
        $this->putFile("{$dir}/vd.m3u8", $this->playlist([
            'index-000.ts',
            'https://total.stepsio.com/api/vd/ca63ba0c-uuid/stream/720/index-001.ts',
            'index-002.ts?v=2',
        ]));
        $this->putFile("{$dir}/index-002.ts", str_repeat('c', 30));
        $this->putFile("{$dir}/index-000.ts", str_repeat('a', 10));
        $this->putFile("{$dir}/index-001.ts", str_repeat('b', 20));
        $this->putFile('total/ca63ba0c-uuid/480/index-000.ts', str_repeat('x', 99)); // another quality

        $source = (new DownloadSource)->for($this->video(), '720');

        $this->assertSame('legacy', $source->bucket);
        $this->assertSame("{$dir}/", $source->prefix);
        $this->assertSame([['index-000.ts', 10, 10.0], ['index-001.ts', 20, 10.1], ['index-002.ts', 30, 10.2]], $source->segments);
        $this->assertSame(60, $source->totalSize);
        $this->assertSame(30.3, $source->duration);
        $this->assertSame(DownloadSource::version($source->segments), $source->version);
    }

    public function test_version_matches_the_worker(): void
    {
        // Same inputs and output as the Worker's test/source.test.ts.
        $this->assertSame('f6f1bf1e97abbea5', DownloadSource::version([['index-000.ts', 891888], ['index-001.ts', 758592]]));
    }

    public function test_missing_segment_is_broken(): void
    {
        $this->putFile('total/ca63ba0c-uuid/720/vd.m3u8', $this->playlist(['index-000.ts', 'index-001.ts']));
        $this->putFile('total/ca63ba0c-uuid/720/index-000.ts', 'a');

        $this->assertUnavailable(DownloadUnavailableException::BROKEN, fn () => (new DownloadSource)->for($this->video(), '720'));
    }

    public function test_empty_segment_is_broken(): void
    {
        $this->putFile('total/ca63ba0c-uuid/720/vd.m3u8', $this->playlist(['index-000.ts']));
        $this->putFile('total/ca63ba0c-uuid/720/index-000.ts', '');

        $this->assertUnavailable(DownloadUnavailableException::BROKEN, fn () => (new DownloadSource)->for($this->video(), '720'));
    }

    public function test_no_playlist_is_not_found(): void
    {
        $this->assertUnavailable(DownloadUnavailableException::NOT_FOUND, fn () => (new DownloadSource)->for($this->video(), '720'));
    }

    public function test_quality_with_a_path_is_refused(): void
    {
        $this->assertUnavailable(DownloadUnavailableException::NOT_FOUND, fn () => (new DownloadSource)->for($this->video(), '../480'));
    }

    public function test_only_ready_hot_legacy_videos(): void
    {
        $source = new DownloadSource;
        $this->assertUnavailable(DownloadUnavailableException::NOT_READY, fn () => $source->for($this->video(['status' => HlsVideo::PROCESSING]), '720'));
        $this->assertUnavailable(DownloadUnavailableException::NOT_HOT, fn () => $source->for($this->video(['storage_state' => HlsVideo::STORAGE_ARCHIVED]), '720'));
        $this->assertUnavailable(DownloadUnavailableException::BUCKET, fn () => $source->for($this->video(['storage_bucket' => HlsVideo::STORAGE_TENANT]), '720'));
    }

    private function assertUnavailable(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected DownloadUnavailableException ({$reason})");
        } catch (DownloadUnavailableException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }
}
