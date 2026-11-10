<?php

namespace HlsVideos\Tests\PlayerDebug;

use HlsVideos\Models\HlsVideo;
use HlsVideos\Providers\HlsVideoServiceProvider;
use HlsVideos\Services\PlayerDebug;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

class PlayerDebugTest extends TestCase
{
    private const VIDEO = '774cb7fd-5b87-41d9-919c-c093a3d9b41b';

    private array $logged = [];

    protected function getPackageProviders($app)
    {
        return [HlsVideoServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('hls-videos.storage_stream_domain', 'cdn.example.test');
        $app['config']->set('hls-videos.player_debug.redis', null);
        // The Plyr view asks the app's admin guard whether to send the student's headers.
        $app['config']->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('currentTenant', (object) ['id' => 3, 'media_folder' => 'city-center']);
        Route::get('stream/{id}', fn () => '')->name('dashboard.video.stream');
    }

    public function test_logging_is_on_only_for_the_viewer_it_was_turned_on_for(): void
    {
        $this->artisan('hls-videos:debug-player', ['viewer' => '1234', '--hours' => 2])->assertExitCode(0);

        $this->assertNotNull(PlayerDebug::clientConfig(1234, $this->video()));
        $this->assertNull(PlayerDebug::clientConfig(999, $this->video()));
        $this->assertNull(PlayerDebug::clientConfig(null, $this->video()));

        $this->artisan('hls-videos:debug-player', ['viewer' => '1234', '--off' => true])->assertExitCode(0);
        $this->assertNull(PlayerDebug::clientConfig(1234, $this->video()));
    }

    public function test_the_token_names_the_viewer_and_refuses_tampering_and_expiry(): void
    {
        $token = PlayerDebug::token('3', '1234', self::VIDEO, time() + 60);
        $this->assertSame(['3', '1234', self::VIDEO], PlayerDebug::verify($token));

        [$payload, $sig] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode(json_encode(['3', '1', self::VIDEO, time() + 60])), '+/', '-_'), '=');
        $this->assertNull(PlayerDebug::verify($forged.'.'.$sig));
        $this->assertNull(PlayerDebug::verify(PlayerDebug::token('3', '1234', self::VIDEO, time() - 1)));
        $this->assertNull(PlayerDebug::verify('garbage'));
    }

    public function test_the_endpoint_writes_one_line_per_event_with_who_was_watching(): void
    {
        Log::shouldReceive('channel')->with('hls-player-debug')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function ($type, $context) {
            $this->logged[] = [$type, $context];
        });
        $token = PlayerDebug::token('3', '1234', self::VIDEO, time() + 60);

        $this->call('POST', route('hls.videos.player-log'), [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode([
            'token' => $token,
            'session' => 'abc',
            'events' => [
                ['seq' => 1, 'ms' => 5, 'type' => 'session', 'data' => ['ua' => 'Android WebView']],
                ['seq' => 2, 'ms' => 900, 'type' => 'hls.error', 'data' => ['details' => 'fragLoadTimeOut']],
            ],
        ]))->assertNoContent();

        $this->assertCount(2, $this->logged);
        $this->assertSame('hls.error', $this->logged[1][0]);
        $this->assertSame('1234', $this->logged[1][1]['viewer']);
        $this->assertSame('3', $this->logged[1][1]['tenant']);
        $this->assertSame(self::VIDEO, $this->logged[1][1]['video']);
        $this->assertStringContainsString('fragLoadTimeOut', $this->logged[1][1]['data']);
    }

    public function test_events_from_every_node_are_kept_together_and_shown_in_order(): void
    {
        $redis = new FakeRedis;
        Redis::shouldReceive('connection')->with('default')->andReturn($redis);
        config(['hls-videos.player_debug.redis' => 'default']);
        $token = PlayerDebug::token('3', '1234', self::VIDEO, time() + 60);
        $post = fn (array $events) => $this->call('POST', route('hls.videos.player-log'), [], [], [], ['CONTENT_TYPE' => 'text/plain'],
            json_encode(['token' => $token, 'session' => 'page1', 'events' => $events]));

        // Two batches of one page, landing on different nodes, the later one first.
        $post([['seq' => 3, 'ms' => 3574, 'at' => '2026-11-10T20:32:48.831Z', 'type' => 'media.abort', 'data' => ['t' => 0]]]);
        $post([
            ['seq' => 1, 'ms' => 3, 'at' => '2026-11-10T20:32:45.260Z', 'type' => 'session', 'data' => ['ua' => 'wv']],
            ['seq' => 2, 'ms' => 52, 'at' => '2026-11-10T20:32:45.309Z', 'type' => 'hls.manifest_loading', 'data' => null],
        ]);

        $this->assertCount(3, PlayerDebug::stored('1234'));
        $this->artisan('hls-videos:player-log', ['viewer' => '1234'])
            ->expectsOutput('     1      0.0s  session                {"ua":"wv"}')
            ->expectsOutput('     2      0.1s  hls.manifest_loading   null')
            ->expectsOutput('     3      3.6s  media.abort            {"t":0}')
            ->assertExitCode(0);

        $this->artisan('hls-videos:player-log', ['viewer' => '1234', '--clear' => true])->assertExitCode(0);
        $this->assertSame([], PlayerDebug::stored('1234'));
    }

    public function test_the_endpoint_refuses_a_bad_token(): void
    {
        $this->call('POST', route('hls.videos.player-log'), [], [], [], ['CONTENT_TYPE' => 'text/plain'],
            json_encode(['token' => 'x.y', 'events' => [['type' => 'session']]]))
            ->assertStatus(403);
    }

    public function test_both_players_render_valid_javascript_with_logging_on_and_off(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }

        foreach (['plyr', 'videojs'] as $player) {
            config(['hls-videos.player' => $player]);
            foreach ([null, '1234'] as $viewer) {
                if ($viewer) {
                    PlayerDebug::enable($viewer, 1);
                }
                $html = Blade::render('<x-hls-video :video="$video" :debugViewer="$viewer" />@stack(\'hls-scripts\')', [
                    'video' => $this->video(),
                    'viewer' => $viewer,
                ]);

                preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $m);
                $this->assertNotEmpty($m[1]);
                $this->assertSame($viewer !== null, str_contains($html, '"token"'), "{$player} viewer=".var_export($viewer, true));
                foreach ($m[1] as $i => $js) {
                    $file = tempnam(sys_get_temp_dir(), 'pd').'.js';
                    file_put_contents($file, $js);
                    exec(escapeshellarg($node).' --check '.escapeshellarg($file).' 2>&1', $out, $code);
                    @unlink($file);
                    $this->assertSame(0, $code, "{$player} script {$i}: ".implode("\n", $out));
                }
            }
        }
    }

    private function video(): HlsVideo
    {
        $video = new HlsVideo;
        $video->forceFill(['id' => self::VIDEO, 'status' => HlsVideo::READY, 'stream_data' => ['thumb_disk' => 'r2']]);

        return $video;
    }
}

class FakeRedis
{
    public array $lists = [];

    public function rpush($key, ...$values)
    {
        foreach ($values as $v) {
            $this->lists[$key][] = $v;
        }

        return count($this->lists[$key]);
    }

    public function ltrim($key, $start, $stop)
    {
        $this->lists[$key] = array_slice($this->lists[$key] ?? [], $start);

        return true;
    }

    public function expire($key, $seconds)
    {
        return true;
    }

    public function lrange($key, $start, $stop)
    {
        return $this->lists[$key] ?? [];
    }

    public function del($key)
    {
        unset($this->lists[$key]);

        return 1;
    }
}
