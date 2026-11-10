<?php
namespace HlsVideos\Providers;

use HlsVideos\Components\{VideoManeger, Video};
use HlsVideos\Console\ArchiveVideo;
use HlsVideos\Console\DebugPlayer;
use HlsVideos\Console\PruneVideoExports;
use HlsVideos\Console\PurgeDeadVideos;
use HlsVideos\Console\PurgeTrashedVideos;
use HlsVideos\Console\UnArchiveVideo;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class HlsVideoServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../../config/hls-videos.php' => config_path('hls-videos.php'),
        ], 'config');

        // Player debug log file, unless the app defines the channel itself.
        $channel = config('hls-videos.player_debug.log_channel', 'hls-player-debug');
        if (! config("logging.channels.{$channel}")) {
            config(["logging.channels.{$channel}" => [
                'driver' => 'daily',
                'path' => storage_path('logs/player-debug.log'),
                'level' => 'debug',
                'days' => 14,
            ]]);
        }

        // Views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'hls-videos');

        // Routes
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        // Migrations
        $this->loadMigrationsFrom(__DIR__.'/../Database/migrations');

        // Register blade component
        Blade::component('hls-video-manager', VideoManeger::class);
        Blade::component('hls-video', Video::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PruneVideoExports::class, PurgeTrashedVideos::class, PurgeDeadVideos::class, DebugPlayer::class, ArchiveVideo::class, UnArchiveVideo::class]);
        }
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/hls-videos.php', 'hls-videos'
        );
    }
}
