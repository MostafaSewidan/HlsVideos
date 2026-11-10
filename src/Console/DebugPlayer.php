<?php

namespace HlsVideos\Console;

use HlsVideos\Services\PlayerDebug;
use Illuminate\Console\Command;

/**
 *   php artisan tenants:artisan "hls-videos:debug-player 1234 --hours=48" --tenant=3
 *   php artisan tenants:artisan "hls-videos:debug-player 1234 --off" --tenant=3
 *
 * Records everything the player does for one viewer (by the id the app passes,
 * the student's id) into storage/logs/player-debug-YYYY-MM-DD.log.
 */
class DebugPlayer extends Command
{
    protected $signature = 'hls-videos:debug-player
        {viewer : the student id}
        {--hours=48 : how long to keep logging}
        {--off : stop logging now}';

    protected $description = 'Log every player event of one student for a few hours';

    public function handle(): int
    {
        $viewer = (string) $this->argument('viewer');

        if ($this->option('off')) {
            PlayerDebug::disable($viewer);
            $this->info("Player logging is off for viewer {$viewer}.");

            return self::SUCCESS;
        }

        $hours = max(1, min(24 * 14, (int) $this->option('hours')));
        $until = PlayerDebug::enable($viewer, $hours);
        $this->info("Player logging is on for viewer {$viewer} until {$until->format('Y-m-d H:i')} ({$hours}h).");
        $this->line('It starts with the next video they open. Logs: storage/logs/player-debug-*.log');

        return self::SUCCESS;
    }
}
