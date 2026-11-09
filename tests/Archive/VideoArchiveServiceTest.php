<?php

namespace HlsVideos\Tests\Archive;

use HlsVideos\Archive\ArchiveConfig;
use HlsVideos\Archive\ArchiveException;
use HlsVideos\Archive\BrokenVideoException;
use HlsVideos\Archive\VideoArchiver;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Providers\HlsVideoServiceProvider;
use HlsVideos\Services\VideoArchiveService;
use HlsVideos\Services\VideoService;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;

/**
 * archive() and unArchive() against a real (sqlite) hls_videos table and the real
 * engine, with local folders as the bucket, the test bucket and the box.
 */
class VideoArchiveServiceTest extends TestCase
{
    use TempDirs;

    private const MF = 'tenant1';

    private const ID = '774cb7fd-5b87-41d9-919c-c093a3d9b41b';

    private string $source;

    private string $box;

    private string $test;

    protected function getPackageProviders($app)
    {
        return [HlsVideoServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        exec('command -v rclone', $_, $code);
        if ($code !== 0) {
            $this->markTestSkipped('rclone is not installed');
        }
        $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../src/Database/migrations'), '--realpath' => true])->run();

        $this->source = $this->tempDir();
        $this->box = $this->tempDir();
        $this->test = $this->tempDir();
        $this->app->instance('currentTenant', (object) ['media_folder' => self::MF]);
        config([
            'hls-videos.archive.sources.legacy' => $this->source,
            'hls-videos.archive.box.name' => 'u712746',
            'hls-videos.archive.delete_enabled' => false,
        ]);
        $this->useBox($this->box);

        $this->makeVideoFiles();
        DB::table('hls_videos')->insert([
            'id' => self::ID, 'status' => HlsVideo::READY, 'original_extension' => 'mp4', 'file_name' => 'vd.mp4',
            'original_file_name' => 'lesson.mp4', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
    }

    public function test_new_columns_default_to_a_hot_legacy_video(): void
    {
        $video = HlsVideo::find(self::ID);

        $this->assertSame(HlsVideo::STORAGE_LEGACY, $video->storage_bucket);
        $this->assertSame(HlsVideo::STORAGE_HOT, $video->storage_state);
    }

    public function test_archive_with_delete_off_keeps_the_video_hot_and_its_files_on_r2(): void
    {
        $video = HlsVideo::find(self::ID)->archive();

        $this->assertSame(HlsVideo::STORAGE_HOT, $video->storage_state);
        $this->assertSame('u712746', $video->archive_box);
        $this->assertSame(self::MF.'/'.self::ID, $video->archive_path);
        $this->assertNotNull($video->archived_at);
        $this->assertNull($video->storage_error);
        $this->assertSame(['360', '720', 'meta'], array_column($video->archive_manifest['tars'], 'name'));
        $this->assertArrayNotHasKey('files', $video->archive_manifest['tars'][0]);
        $this->assertSame($video->archive_manifest['total_bytes'], $video->hot_size_bytes);
        $this->assertFileExists($this->videoFile('720/index-0.ts'));
        $this->assertFileExists("{$this->box}/".self::MF.'/'.self::ID.'/720.tar');
    }

    public function test_archive_with_delete_on_then_unarchive_brings_the_segments_back(): void
    {
        config(['hls-videos.archive.delete_enabled' => true]);
        $original = $this->videoFiles();

        $video = HlsVideo::find(self::ID)->archive();

        $this->assertSame(HlsVideo::STORAGE_ARCHIVED, $video->storage_state);
        $this->assertTrue($video->is_archived);
        $this->assertFileDoesNotExist($this->videoFile('720/index-0.ts'));
        $this->assertFileExists($this->videoFile('720/vd.m3u8'));
        $this->assertFileExists($this->videoFile('thumb.jpg'));

        $video = $video->unArchive();

        $this->assertSame(HlsVideo::STORAGE_HOT, $video->storage_state);
        $this->assertNotNull($video->restored_at);
        $this->assertSame($original, $this->videoFiles());
    }

    public function test_unarchive_to_the_test_target_changes_nothing_on_the_video(): void
    {
        $video = HlsVideo::find(self::ID)->archive();

        $video->unArchive($this->test);

        $this->assertSame(HlsVideo::STORAGE_HOT, $video->fresh()->storage_state);
        $this->assertFileEquals($this->videoFile('720/index-1.ts'), "{$this->test}/".self::MF.'/'.self::ID.'/720/index-1.ts');
    }

    public function test_a_video_already_being_archived_is_not_touched(): void
    {
        DB::table('hls_videos')->update(['storage_state' => HlsVideo::STORAGE_ARCHIVING]);

        try {
            HlsVideo::find(self::ID)->archive();
            $this->fail('archived a video another run was working on');
        } catch (ArchiveException $e) {
            $this->assertStringContainsString("is 'archiving', expected 'hot'", $e->getMessage());
        }
        $this->assertSame(HlsVideo::STORAGE_ARCHIVING, HlsVideo::find(self::ID)->storage_state);
        $this->assertDirectoryDoesNotExist("{$this->box}/".self::MF);
    }

    public function test_broken_video_is_marked_failed(): void
    {
        unlink($this->videoFile('360/index-1.ts'));

        try {
            HlsVideo::find(self::ID)->archive();
            $this->fail('a broken video was archived');
        } catch (BrokenVideoException $e) {
        }
        $video = HlsVideo::find(self::ID);
        $this->assertSame(HlsVideo::STORAGE_FAILED, $video->storage_state);
        $this->assertStringContainsString('360/vd.m3u8 -> index-1.ts', $video->storage_error);
        $this->assertNull($video->archive_path);
    }

    public function test_failure_on_the_way_returns_the_video_to_hot(): void
    {
        config(['hls-videos.archive.delete_enabled' => true]);
        $this->useBox('/dev/null/not-a-folder');

        try {
            HlsVideo::find(self::ID)->archive();
            $this->fail('archive did not fail');
        } catch (ArchiveException $e) {
        }
        $video = HlsVideo::find(self::ID);
        $this->assertSame(HlsVideo::STORAGE_HOT, $video->storage_state);
        $this->assertNotEmpty($video->storage_error);
        $this->assertFileExists($this->videoFile('720/index-0.ts'));
    }

    public function test_video_in_use_again_before_the_delete_stays_hot(): void
    {
        config(['hls-videos.archive.delete_enabled' => true]);

        $video = HlsVideo::find(self::ID)->archive(fn () => false);

        $this->assertSame(HlsVideo::STORAGE_HOT, $video->storage_state);
        $this->assertFileExists($this->videoFile('720/index-0.ts'));
    }

    public function test_unarchive_refuses_a_video_that_is_not_archived(): void
    {
        $video = HlsVideo::find(self::ID)->archive();

        $this->expectExceptionMessage("is 'hot', expected 'archived'");
        $video->unArchive();
    }

    public function test_video_that_is_not_ready_is_not_archived(): void
    {
        DB::table('hls_videos')->update(['status' => HlsVideo::PROCESSING]);

        $this->expectExceptionMessage('is not ready');
        HlsVideo::find(self::ID)->archive();
    }

    public function test_master_playlist_load_records_last_played_at_once_an_hour_without_touching_updated_at(): void
    {
        $updatedAt = HlsVideo::find(self::ID)->updated_at;

        VideoService::touchLastPlayed(self::ID);
        $first = HlsVideo::find(self::ID)->last_played_at;
        $this->travel(10)->minutes();
        VideoService::touchLastPlayed(self::ID);

        $video = HlsVideo::find(self::ID);
        $this->assertNotNull($first);
        $this->assertEquals($first, $video->last_played_at);
        $this->assertEquals($updatedAt, $video->updated_at);

        $this->travel(61)->minutes();
        VideoService::touchLastPlayed(self::ID);
        $this->assertTrue(HlsVideo::find(self::ID)->last_played_at->gt($first));
    }

    private function useBox(string $box): void
    {
        config(['hls-videos.archive.box.remote' => $box]);
        $this->app->instance(VideoArchiveService::class, new VideoArchiveService(new VideoArchiver(
            new ArchiveConfig(workDir: $this->tempDir(), boxRemote: $box, verifyMode: 'download', r2Transfers: 4)
        )));
    }

    private function makeVideoFiles(): void
    {
        foreach (['720' => 3, '360' => 2] as $q => $count) {
            $playlist = "#EXTM3U\n";
            for ($n = 0; $n < $count; $n++) {
                $this->putFile("{$q}/index-{$n}.ts", random_bytes(random_int(1000, 50000)));
                $playlist .= "#EXTINF:10,\nindex-{$n}.ts\n";
            }
            $this->putFile("{$q}/vd.m3u8", $playlist);
        }
        $this->putFile('index.m3u8', "#EXTM3U\n720/vd.m3u8\n");
        $this->putFile('thumb.jpg', random_bytes(3000));
    }

    private function putFile(string $path, string $bytes): void
    {
        @mkdir(dirname($this->videoFile($path)), 0755, true);
        file_put_contents($this->videoFile($path), $bytes);
    }

    private function videoFile(string $path): string
    {
        return "{$this->source}/".self::MF.'/'.self::ID."/{$path}";
    }

    private function videoFiles(): array
    {
        $dir = "{$this->source}/".self::MF.'/'.self::ID;
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[substr($f->getPathname(), strlen($dir) + 1)] = hash_file('sha256', $f->getPathname());
        }
        ksort($out);

        return $out;
    }
}
