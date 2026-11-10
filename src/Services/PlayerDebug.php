<?php

namespace HlsVideos\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Player debug logging for one viewer (a student) of the current tenant.
 *
 * Turned on with `hls-videos:debug-player {viewer}` for a few hours. While it is
 * on, every player page that viewer opens records what happens in the browser
 * (player events, network errors, CDN switches, JS errors) and posts it to
 * /hls/videos/player-log, which writes it to storage/logs/player-debug-*.log.
 *
 * The page gets a signed token for (tenant, viewer, video), so the endpoint needs
 * no session and nobody else can write to the log.
 */
class PlayerDebug
{
    public static function enable(string $viewer, int $hours): \DateTimeInterface
    {
        $until = now()->addHours($hours);
        Cache::put(static::key($viewer), $until->timestamp, $until);

        return $until;
    }

    public static function disable(string $viewer): void
    {
        Cache::forget(static::key($viewer));
    }

    /** Unix time the logging stops for this viewer, or null when off. */
    public static function until(?string $viewer): ?int
    {
        if (! config('hls-videos.player_debug.enabled', true) || $viewer === null || $viewer === '') {
            return null;
        }
        $until = Cache::get(static::key($viewer));

        return $until && $until > time() ? (int) $until : null;
    }

    /** What the player page needs to log, or null when logging is off for this viewer. */
    public static function clientConfig($viewer, $video): ?array
    {
        $viewer = $viewer === null ? null : (string) $viewer;
        $until = static::until($viewer);
        if (! $until || ! $video) {
            return null;
        }

        return [
            'url' => route('hls.videos.player-log'),
            'token' => static::token(static::tenantId(), $viewer, (string) $video->id, $until),
            'maxEvents' => (int) config('hls-videos.player_debug.max_events', 4000),
        ];
    }

    public static function token(string $tenant, string $viewer, string $videoId, int $until): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode([$tenant, $viewer, $videoId, $until])), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, static::secret());
    }

    /** [tenant, viewer, videoId] when the token is genuine and not expired, else null. */
    public static function verify(?string $token): ?array
    {
        if (! $token || ! Str::contains($token, '.')) {
            return null;
        }
        [$payload, $signature] = explode('.', $token, 2);
        if (! hash_equals(hash_hmac('sha256', $payload, static::secret()), $signature)) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        if (! is_array($data) || count($data) !== 4 || (int) $data[3] < time()) {
            return null;
        }

        return [(string) $data[0], (string) $data[1], (string) $data[2]];
    }

    private static function key(string $viewer): string
    {
        return 'hls-player-debug:'.static::tenantId().':'.$viewer;
    }

    private static function tenantId(): string
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        return (string) ($tenant->id ?? $tenant->media_folder ?? 'none');
    }

    private static function secret(): string
    {
        return 'hls-player-debug|'.config('app.key');
    }
}
