<?php

namespace HlsVideos\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Remembers which step an encode is on, so a failure can say where it stopped.
 *
 * The encoder node is destroyed soon after a video fails, taking its logs with
 * it; only the exception message reaches the master (failed_reason). A worker
 * timeout kills the process mid-step and leaves only Laravel's generic "run too
 * long" message, so the steps live in the cache, where failed() can read them.
 */
class EncodeProgress
{
    protected const TTL_SECONDS = 86400;

    public static function start($videoId, string $step): void
    {
        self::save($videoId, [[$step, time()]]);
    }

    public static function step($videoId, string $step): void
    {
        $steps = self::load($videoId);
        $steps[] = [$step, time()];
        self::save($videoId, $steps);
    }

    /**
     * Percentage of the current step (ffmpeg's progress during encode), and
     * when it last moved, so a failure can tell a frozen encode from a slow one.
     */
    public static function progress($videoId, int $percent): void
    {
        $steps = self::load($videoId);
        if (empty($steps)) {
            return;
        }

        $last = count($steps) - 1;
        $steps[$last][2] = $percent;
        $steps[$last][3] = time();
        self::save($videoId, $steps);
    }

    public static function forget($videoId): void
    {
        try {
            Cache::forget(self::key($videoId));
        } catch (\Throwable $e) {
            // Progress tracking must never break an encode.
        }
    }

    /**
     * e.g. "stopped at upload after 1h 52m (download 40s, probe 2s, thumbnail 3s, encode 1h 10m, playlists 1s, upload 42m+)"
     * or, for an encode that froze: "... encode 2h 9m+ at 34%, no progress for 1h 50m)"
     */
    public static function describe($videoId): ?string
    {
        $steps = self::load($videoId);
        if (empty($steps)) {
            return null;
        }

        $now = time();
        $parts = [];
        foreach ($steps as $i => $step) {
            [$name, $startedAt] = $step;
            $endedAt = $steps[$i + 1][1] ?? null;
            $part = $name.' '.self::duration(($endedAt ?? $now) - $startedAt).($endedAt === null ? '+' : '');

            // Progress only matters for the step that was still running.
            if ($endedAt === null && isset($step[2])) {
                $part .= " at {$step[2]}%, no progress for ".self::duration($now - $step[3]);
            } elseif ($endedAt === null && $name === 'encode') {
                $part .= ' at 0%, no progress reported';
            }

            $parts[] = $part;
        }

        [$current] = end($steps);

        return "stopped at {$current} after ".self::duration($now - $steps[0][1]).' ('.implode(', ', $parts).')';
    }

    protected static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);

        return $minutes < 60 ? "{$minutes}m" : intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }

    protected static function load($videoId): array
    {
        try {
            return Cache::get(self::key($videoId), []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected static function save($videoId, array $steps): void
    {
        try {
            Cache::put(self::key($videoId), $steps, self::TTL_SECONDS);
        } catch (\Throwable $e) {
            // Progress tracking must never break an encode.
        }
    }

    protected static function key($videoId): string
    {
        return "hls-encode-progress:{$videoId}";
    }
}
