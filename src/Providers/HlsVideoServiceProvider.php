<?php
namespace  HlsVideos\Providers;

use HlsVideos\Components\VideoManeger;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class HlsVideoServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../../config/hls-videos.php' => config_path('hls-videos.php'),
        ], 'config');

        // Views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'hls-videos');

        // Routes
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        // Migrations
        $this->loadMigrationsFrom(__DIR__.'/../Database/migrations');
        
        // Register blade component
        Blade::component('hls-video-manager', VideoManeger::class);
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/hls-videos.php', 'hls-videos'
        );
    }
}
