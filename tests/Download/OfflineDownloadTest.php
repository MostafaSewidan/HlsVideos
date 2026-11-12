<?php

namespace HlsVideos\Tests\Download;

use HlsVideos\Exceptions\DownloadUnavailableException;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Providers\HlsVideoServiceProvider;
use HlsVideos\Repositories\HlsVideoExportRepository;
use HlsVideos\Services\Download\DownloadSource;
use HlsVideos\Services\Download\DownloadUrlSigner;
use HlsVideos\Services\Download\OfflineDownload;
use HlsVideos\Services\Download\OfflinePlaylistBuilder;
use HlsVideos\Services\VideoService;
use HlsVideos\Tests\Archive\TempDirs;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OfflineDownloadTest extends TestCase
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

    private function service(array $qualities): OfflineDownload
    {
        $videos = new class($qualities) extends HlsVideoExportRepository {
            public function __construct(private array $list)
            {
            }

            public function qualities(HlsVideo $video): Collection
            {
                return collect($this->list);
            }
        };
        $signer = new DownloadUrlSigner(['url' => 'https://dl.test', 'backup_url' => 'https://backup.test/dl', 'secret' => 's', 'url_ttl_minutes' => 60]);

        return new OfflineDownload(new DownloadSource, new OfflinePlaylistBuilder, $signer, $videos);
    }

    private function video(array $attributes = []): HlsVideo
    {
        return (new HlsVideo)->forceFill($attributes + [
            'id' => 'vid', 'status' => HlsVideo::READY, 'storage_state' => HlsVideo::STORAGE_HOT, 'storage_bucket' => HlsVideo::STORAGE_LEGACY,
            'stream_data' => ['hls_key' => base64_encode('0123456789abcdef')],
        ]);
    }

    private function quality(string $quality, array $sizes): void
    {
        $dir = "{$this->root}/total/vid/{$quality}";
        @mkdir($dir, 0777, true);
        $playlist = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-KEY:METHOD=AES-128,URI=\"secret.key\",IV=0x01\n";
        foreach ($sizes as $i => $size) {
            $name = sprintf('index-%03d.ts', $i);
            $playlist .= "#EXTINF:10.0,\n{$name}\n";
            if ($size !== null) {
                file_put_contents("{$dir}/{$name}", str_repeat('x', $size));
            }
        }
        file_put_contents("{$dir}/vd.m3u8", $playlist."#EXT-X-ENDLIST\n");
    }

    public function test_options_skip_a_quality_with_missing_files(): void
    {
        $this->quality('720', [100, 200]);
        $this->quality('480', [50, null]); // a segment is missing on R2
        $this->quality('360', [10, 20]);

        $options = $this->service(['720', '480', '360', 'original'])->options($this->video());

        $this->assertSame([
            ['quality' => '720', 'size' => 300, 'duration' => 20.0],
            ['quality' => '360', 'size' => 30, 'duration' => 20.0],
        ], $options);
    }

    public function test_the_container_built_service_reads_the_stream_disk(): void
    {
        // The default disk is not the stream disk, like on production.
        $this->app['config']->set('filesystems.default', 'local');
        $this->quality('720', [100, 200]);
        $this->app->instance(HlsVideoExportRepository::class, new class extends HlsVideoExportRepository {
            public function __construct()
            {
            }

            public function qualities(HlsVideo $video): Collection
            {
                return collect(['720']);
            }
        });

        $options = $this->app->make(OfflineDownload::class)->options($this->video());

        $this->assertSame([['quality' => '720', 'size' => 300, 'duration' => 20.0]], $options);
    }

    public function test_options_throw_when_nothing_can_be_downloaded(): void
    {
        $this->expectException(DownloadUnavailableException::class);
        $this->service(['720'])->options($this->video());
    }

    public function test_options_throw_for_an_archived_video(): void
    {
        $this->quality('720', [100]);
        try {
            $this->service(['720'])->options($this->video(['storage_state' => HlsVideo::STORAGE_ARCHIVED]));
            $this->fail('Expected DownloadUnavailableException');
        } catch (DownloadUnavailableException $e) {
            $this->assertSame(DownloadUnavailableException::NOT_HOT, $e->reason);
        }
    }

    public function test_start_returns_the_contract_shape(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1800000000));
        $this->quality('720', [100, 200]);

        $data = $this->service(['720'])->start($this->video(), '720', '42');

        $this->assertSame(['video_id', 'quality', 'version', 'file', 'playlist'], array_keys($data));
        $this->assertSame('vid', $data['video_id']);
        $this->assertSame('720', $data['quality']);
        $this->assertSame(['name', 'size', 'url', 'backup_url', 'expires_at'], array_keys($data['file']));
        $this->assertSame('720.ts', $data['file']['name']);
        $this->assertSame(300, $data['file']['size']);
        $this->assertStringStartsWith("https://dl.test/legacy/total/vid/720/{$data['version']}.ts?u=42&exp=1800003600&sig=", $data['file']['url']);
        $this->assertStringStartsWith("https://backup.test/dl/legacy/total/vid/720/{$data['version']}.ts?u=42&", $data['file']['backup_url']);
        $this->assertSame('2027-01-15T09:00:00Z', $data['file']['expires_at']);
        $this->assertSame('720.m3u8', $data['playlist']['name']);
        $this->assertStringContainsString("#EXT-X-BYTERANGE:200@100\n720.ts", $data['playlist']['content']);
    }

    public function test_key_response_is_the_raw_16_bytes(): void
    {
        $response = VideoService::keyResponse($this->video());

        $this->assertSame('0123456789abcdef', $response->getContent());
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
    }

    public function test_key_response_404_without_a_key(): void
    {
        $this->expectException(HttpException::class);
        VideoService::keyResponse($this->video(['stream_data' => []]));
    }
}
