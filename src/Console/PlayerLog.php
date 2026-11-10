<?php

namespace HlsVideos\Console;

use HlsVideos\Services\PlayerDebug;
use Illuminate\Console\Command;

/**
 *   php artisan tenants:artisan "hls-videos:player-log 277" --tenant=52
 *   php artisan tenants:artisan "hls-videos:player-log 277 --video=dea095f7" --tenant=52
 *
 * Every event logged for one student (hls-videos:debug-player), from all web
 * nodes, one line each, grouped by page (session) and in the order they happened.
 */
class PlayerLog extends Command
{
    protected $signature = 'hls-videos:player-log
        {viewer : the student id}
        {--video= : only this video (start of its id is enough)}
        {--session= : only this page}
        {--clear : delete the stored events}';

    protected $description = 'Show the player events logged for one student, from every node';

    public function handle(): int
    {
        $viewer = (string) $this->argument('viewer');
        if ($this->option('clear')) {
            PlayerDebug::forget($viewer);
            $this->info("Stored player events of viewer {$viewer} deleted.");

            return self::SUCCESS;
        }

        $events = array_filter(PlayerDebug::stored($viewer), function (array $e) {
            return (! $this->option('video') || str_starts_with((string) ($e['video'] ?? ''), $this->option('video')))
                && (! $this->option('session') || ($e['session'] ?? '') === $this->option('session'));
        });
        if (! $events) {
            $this->warn("No stored events for viewer {$viewer}.");

            return self::SUCCESS;
        }

        // Pages in the order they started, events by their sequence on the page.
        $sessions = [];
        foreach ($events as $e) {
            $sessions[$e['session'] ?? ''][] = $e;
        }
        uasort($sessions, fn ($a, $b) => strcmp(min(array_column($a, 'at')), min(array_column($b, 'at'))));

        foreach ($sessions as $session => $rows) {
            usort($rows, fn ($a, $b) => ($a['seq'] ?? 0) <=> ($b['seq'] ?? 0));
            $first = $rows[0];
            $seqs = array_column($rows, 'seq');
            $missing = max($seqs) - count(array_unique($seqs));
            $this->line('');
            $this->info("page {$session}  video {$first['video']}  started {$first['at']} (device clock)  ip {$first['ip']}"
                .($missing > 0 ? "  [{$missing} event(s) missing]" : ''));
            foreach ($rows as $e) {
                $this->line(sprintf('  %4d %8.1fs  %-22s %s', $e['seq'] ?? 0, ($e['ms'] ?? 0) / 1000, $e['type'] ?? '', $e['data'] ?? ''));
            }
        }

        return self::SUCCESS;
    }
}
