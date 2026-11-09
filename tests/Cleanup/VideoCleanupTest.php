<?php

namespace HlsVideos\Tests\Cleanup;

use HlsVideos\Exceptions\LinkedVideosException;
use HlsVideos\Jobs\DeleteHlsVideoFiles;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Providers\HlsVideoServiceProvider;
use HlsVideos\Services\VideoCleanup;
use HlsVideos\Services\VideoUsage;
use HlsVideos\Tests\Archive\TempDirs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;

/**
 * The cleanup rules (base_backend docs/video-archive-plan.md, decisions 19 to 24)
 * against a real sqlite schema, with a stand-in for base_backend's Lesson.
 */
class VideoCleanupTest extends TestCase
{
    use TempDirs;

    private string $disk;

    protected function getPackageProviders($app)
    {
        return [HlsVideoServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../src/Database/migrations'), '--realpath' => true])->run();
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->softDeletes();
        });
        $this->app->instance('currentTenant', new FakeTenant('tenant1'));
        $this->disk = $this->tempDir();
        config([
            'filesystems.disks.r2' => ['driver' => 'local', 'root' => $this->disk],
            'hls-videos.uploaded_videos_disk' => 'r2',
        ]);
        Queue::fake();
    }

    public function test_a_video_counts_as_linked_while_its_lesson_exists_even_in_the_trash(): void
    {
        $video = $this->video();
        $lesson = $this->lesson($video);
        $this->assertTrue($video->isLinked());

        $lesson->delete();
        $this->assertTrue($video->isLinked(), 'a trashed lesson can be restored');

        $lesson->forceDelete();
        $this->assertFalse($video->isLinked());
    }

    public function test_a_folder_does_not_make_a_video_linked(): void
    {
        $video = $this->video();
        $this->entry($this->folder(), $video);

        $this->assertFalse($video->isLinked());
        $this->assertTrue($video->isInLibrary());
    }

    public function test_rows_of_an_unknown_type_count_as_linked(): void
    {
        $video = $this->video();
        DB::table('hls_videoables')->insert(['hls_video_id' => $video->id, 'videoable_type' => 'App\\Gone\\Model', 'videoable_id' => 1]);

        $this->assertTrue($video->isLinked());
    }

    public function test_a_video_that_lost_its_last_lesson_is_trashed_even_in_a_folder(): void
    {
        $video = $this->video();
        $this->entry($this->folder(), $video);
        $lesson = $this->lesson($video);

        $lesson->hlsVideos()->detach();
        $this->assertTrue(app(VideoCleanup::class)->trashIfUnlinked($video));

        $this->assertSoftDeleted('hls_videos', ['id' => $video->id]);
        $this->assertNull(HlsVideo::find($video->id));
        Queue::assertNothingPushed();
    }

    public function test_a_video_another_lesson_uses_is_kept(): void
    {
        $video = $this->video();
        $lesson = $this->lesson($video);
        $this->lesson($video);

        $lesson->hlsVideos()->detach();

        $this->assertFalse(app(VideoCleanup::class)->trashIfUnlinked($video));
        $this->assertNotNull(HlsVideo::find($video->id));
    }

    public function test_taking_a_video_off_a_lesson_keeps_it_when_it_is_in_the_library(): void
    {
        $inLibrary = $this->video();
        $this->entry($this->folder(), $inLibrary);
        $this->lesson($inLibrary)->hlsVideos()->detach();
        $notInLibrary = $this->video();
        $this->lesson($notInLibrary)->hlsVideos()->detach();

        $this->assertFalse(app(VideoCleanup::class)->afterRemovedFromLesson($inLibrary));
        $this->assertTrue(app(VideoCleanup::class)->afterRemovedFromLesson($notInLibrary));

        $this->assertNotNull(HlsVideo::find($inLibrary->id));
        $this->assertSoftDeleted('hls_videos', ['id' => $notInLibrary->id]);
    }

    public function test_deleting_the_last_library_entry_of_an_unlinked_video_deletes_it_now(): void
    {
        $video = $this->video();
        $entry = $this->entry($this->folder(), $video);

        $this->assertSame(1, app(VideoCleanup::class)->deleteFolderEntries([$entry]));

        $this->assertDatabaseMissing('hls_folder_video', ['id' => $entry]);
        $this->assertSoftDeleted('hls_videos', ['id' => $video->id]);
        Queue::assertPushed(DeleteHlsVideoFiles::class, fn ($job) => $job->videoId === $video->id);
    }

    public function test_deleting_one_of_two_entries_only_removes_that_entry(): void
    {
        $video = $this->video();
        $entry = $this->entry($this->folder(), $video);
        $this->entry($this->folder(), $video);

        $this->assertSame(0, app(VideoCleanup::class)->deleteFolderEntries([$entry]));

        $this->assertDatabaseMissing('hls_folder_video', ['id' => $entry]);
        $this->assertNotNull(HlsVideo::find($video->id));
        Queue::assertNothingPushed();
    }

    public function test_deleting_a_linked_video_from_the_library_is_refused_and_nothing_is_deleted(): void
    {
        $free = $this->video();
        $linked = $this->video();
        $this->lesson($linked, 'Lesson 3');
        $folder = $this->folder();
        $entries = [$this->entry($folder, $free), $this->entry($folder, $linked, 'intro')];

        try {
            app(VideoCleanup::class)->deleteFolderEntries($entries);
            $this->fail('expected LinkedVideosException');
        } catch (LinkedVideosException $e) {
            $this->assertSame([['id' => $linked->id, 'video' => 'intro', 'used_in' => ['Lesson 3']]], $e->videos);
        }

        $this->assertSame(2, DB::table('hls_folder_video')->count());
        $this->assertNotNull(HlsVideo::find($free->id));
        Queue::assertNothingPushed();
    }

    public function test_deleting_a_folder_deletes_its_subfolders_and_their_unlinked_videos(): void
    {
        $root = $this->folder();
        $child = $this->folder($root);
        $grandchild = $this->folder($child);
        $deep = $this->video();
        $this->entry($grandchild, $deep);
        $elsewhere = $this->video();
        $this->entry($child, $elsewhere);
        $this->entry($this->folder(), $elsewhere);

        $this->assertSame(1, app(VideoCleanup::class)->deleteFolders([$root]));

        $this->assertSame(0, DB::table('hls_folders')->whereIn('id', [$root, $child, $grandchild])->count());
        $this->assertSoftDeleted('hls_videos', ['id' => $deep->id]);
        $this->assertNotNull(HlsVideo::find($elsewhere->id));
        Queue::assertPushed(DeleteHlsVideoFiles::class, 1);
    }

    public function test_a_folder_holding_a_linked_video_deep_down_is_not_deleted(): void
    {
        $root = $this->folder();
        $child = $this->folder($root);
        $linked = $this->video();
        $this->entry($child, $linked);
        $this->lesson($linked);

        $this->expectException(LinkedVideosException::class);
        try {
            app(VideoCleanup::class)->deleteFolders([$root]);
        } finally {
            $this->assertSame(2, DB::table('hls_folders')->whereIn('id', [$root, $child])->count());
            $this->assertSame(1, DB::table('hls_folder_video')->count());
        }
    }

    public function test_the_folder_delete_endpoint_returns_the_linked_videos(): void
    {
        $folder = $this->folder();
        $linked = $this->video();
        $this->entry($folder, $linked, 'intro');
        $this->lesson($linked, 'Lesson 3');

        $this->deleteJson(route('hls.folders.delete'), ['ids' => [$folder]])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.linked_videos.0.video', 'intro')
            ->assertJsonPath('data.linked_videos.0.used_in.0', 'Lesson 3');
    }

    public function test_purge_trashed_deletes_videos_trashed_more_than_seven_days_ago(): void
    {
        $old = $this->video();
        $old->delete();
        DB::table('hls_videos')->where('id', $old->id)->update(['deleted_at' => now()->subDays(8)]);
        $recent = $this->video();
        $recent->delete();

        $this->artisan('hls-videos:purge-trashed')->assertExitCode(0);

        Queue::assertPushed(DeleteHlsVideoFiles::class, 1);
        Queue::assertPushed(DeleteHlsVideoFiles::class, fn ($job) => $job->videoId === $old->id);
    }

    public function test_purge_dead_reports_without_force_and_deletes_with_it(): void
    {
        $dead = $this->video();
        $this->entry($this->folder(), $dead);
        $this->lesson($dead)->forceDelete();
        $used = $this->video();
        $this->lesson($used)->forceDelete();
        $this->lesson($used);
        $trashedLesson = $this->video();
        $this->lesson($trashedLesson)->delete();
        $csv = $this->tempDir().'/list.csv';

        $this->artisan('hls-videos:purge-dead', ['--csv' => $csv])
            ->expectsOutput('  videos whose lessons are all gone: 1 (0.2 hours of video)')
            ->expectsOutput('  dead lesson links on videos still used elsewhere: 1')
            ->assertExitCode(0);
        $this->assertNotNull(HlsVideo::find($dead->id));
        $this->assertStringContainsString($dead->id, file_get_contents($csv));
        Queue::assertNothingPushed();

        $this->artisan('hls-videos:purge-dead', ['--csv' => $csv, '--force' => true])->assertExitCode(0);

        $this->assertSoftDeleted('hls_videos', ['id' => $dead->id]);
        Queue::assertPushed(DeleteHlsVideoFiles::class, 1);
        $this->assertSame(0, VideoUsage::deadRows()->count());
        $this->assertTrue($used->isLinked());
        $this->assertNotNull(HlsVideo::find($trashedLesson->id));
    }

    public function test_the_delete_job_removes_a_trashed_video_and_is_fine_when_it_is_already_gone(): void
    {
        $video = $this->video();
        @mkdir("{$this->disk}/tenant1/{$video->id}/720", 0755, true);
        file_put_contents("{$this->disk}/tenant1/{$video->id}/720/index-0.ts", 'x');
        $video->delete();

        $job = new DeleteHlsVideoFiles($video->id, app('currentTenant'));
        $job->handle();

        $this->assertDirectoryDoesNotExist("{$this->disk}/tenant1/{$video->id}");
        $this->assertSame(0, DB::table('hls_videos')->where('id', $video->id)->count());

        $job->handle();
        $this->addToAssertionCount(1);
    }

    private function video(): HlsVideo
    {
        $id = (string) Str::uuid();
        DB::table('hls_videos')->insert([
            'id' => $id, 'status' => HlsVideo::READY, 'original_extension' => 'mp4', 'file_name' => 'vd.mp4',
            'original_file_name' => 'lesson.mp4', 'stream_data' => json_encode(['duration' => 600]),
            'created_at' => now()->subMonth(), 'updated_at' => now()->subMonth(),
        ]);

        return HlsVideo::find($id);
    }

    private function lesson(HlsVideo $video, string $title = 'Lesson'): Lesson
    {
        $lesson = Lesson::create(['title' => $title]);
        $lesson->hlsVideos()->attach($video->id);

        return $lesson;
    }

    private function folder(?int $parent = null): int
    {
        return DB::table('hls_folders')->insertGetId([
            'title' => 'folder '.Str::random(6), 'parent_id' => $parent, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function entry(int $folder, HlsVideo $video, string $title = 'video'): int
    {
        return DB::table('hls_folder_video')->insertGetId([
            'folder_id' => $folder, 'hls_video_id' => $video->id, 'title' => $title, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

class Lesson extends Model
{
    use SoftDeletes, \HlsVideos\Traits\HasHlsVideo;

    protected $table = 'lessons';

    protected $guarded = [];

    public $timestamps = false;
}

class FakeTenant
{
    public function __construct(public string $media_folder)
    {
    }

    public function makeCurrent(): self
    {
        app()->instance('currentTenant', $this);

        return $this;
    }
}
