<?php

namespace HlsVideos\Http\Controllers;

use HlsVideos\Services\PlayerDebug;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives batches of player events from a page with debug logging on
 * (PlayerDebug) and writes one log line per event. Never fails the page.
 */
class PlayerLogController extends Controller
{
    private const MAX_EVENTS_PER_REQUEST = 300;

    public function store(Request $request)
    {
        // sendBeacon posts text/plain: read the body ourselves.
        $body = json_decode($request->getContent(), true);
        if (! is_array($body)) {
            return response()->noContent();
        }

        $who = PlayerDebug::verify($body['token'] ?? null);
        if (! $who) {
            return response()->noContent(403);
        }
        [$tenant, $viewer, $videoId] = $who;

        $context = [
            'tenant' => $tenant,
            'viewer' => $viewer,
            'video' => $videoId,
            'session' => Str::limit((string) ($body['session'] ?? ''), 40, ''),
            'ip' => $request->ip(),
        ];

        $log = Log::channel(config('hls-videos.player_debug.log_channel', 'hls-player-debug'));
        $stored = [];
        $received = now()->format('Y-m-d H:i:s.v');
        foreach (array_slice((array) ($body['events'] ?? []), 0, self::MAX_EVENTS_PER_REQUEST) as $event) {
            if (! is_array($event)) {
                continue;
            }
            $type = preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) ($event['type'] ?? 'event')) ?: 'event';
            $data = json_encode($event['data'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $line = $context + [
                'seq' => (int) ($event['seq'] ?? 0),
                'ms' => (int) ($event['ms'] ?? 0),
                'at' => Str::limit((string) ($event['at'] ?? ''), 40, ''),
                'data' => Str::limit((string) $data, 4000),
            ];
            $log->info($type, $line);
            $stored[] = ['type' => $type, 'received' => $received] + $line;
        }

        // Every node behind the load balancer writes here, so one command shows it all.
        try {
            PlayerDebug::store($tenant, $viewer, $stored);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->noContent();
    }
}
