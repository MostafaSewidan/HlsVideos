<?php

namespace  HlsVideos\Services;

class AssetManagerService
{
    protected static array $styles = [
        'https://releases.transloadit.com/uppy/v3.18.0/uppy.min.css',
        'https://cdn.plyr.io/3.7.8/plyr.css',
    ];
    protected static array $scripts = [
        'https://releases.transloadit.com/uppy/v3.18.0/uppy.min.js',
        'https://cdn.plyr.io/3.7.8/plyr.polyfilled.js',
        'https://cdn.jsdelivr.net/npm/hls.js@latest',
    ];

    public static function addStyle(string $href): void
    {
        static::$styles[] = $href;
    }

    public static function addScript(string $src): void
    {
        static::$scripts[] = $src;
    }

    public static function outputStyles(): string
    {
        return collect(static::$styles)
            ->unique()
            ->map(fn($href) => "<link rel='stylesheet' href='{$href}'>")
            ->implode("\n");
    }

    public static function outputScripts(): string
    {
        return collect(static::$scripts)
            ->unique()
            ->map(fn($src) => "<script src='{$src}' defer></script>")
            ->implode("\n");
    }
}
