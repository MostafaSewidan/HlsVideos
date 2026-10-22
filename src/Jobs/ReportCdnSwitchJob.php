<?php

namespace HlsVideos\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends one CDN switch event to steps_monitoring (POST /hooks/cdn-switch).
 *
 * Signed the same way as the monitoring app's other webhooks:
 *   X-Autoscaler-Timestamp: <unix time>
 *   X-Autoscaler-Signature: sha256=<HMAC-SHA256 of "<timestamp>.<body>">
 *
 * The timestamp is taken on every attempt, so a retry is never refused as stale.
 */
class ReportCdnSwitchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 3;

    public $timeout = 20;

    public function __construct(public array $event)
    {
    }

    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(): void
    {
        $url = config('hls-videos.cdn_fallback.report.webhook_url');
        $secret = (string) config('hls-videos.cdn_fallback.report.webhook_secret');

        // Not set up on this server: keep the event in the log so it isn't lost silently.
        if (! $url || $secret === '') {
            Log::info('CDN switch (monitoring webhook not configured)', $this->event);
            return;
        }

        $body = json_encode($this->event, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();

        $response = Http::timeout(10)
            ->withHeaders([
                'X-Autoscaler-Timestamp' => $timestamp,
                'X-Autoscaler-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
            ])
            ->withBody($body, 'application/json')
            ->post($url);

        // 4xx other than 429 will never succeed on retry (bad secret, invalid payload).
        if ($response->clientError() && $response->status() !== 429) {
            Log::warning('CDN switch webhook refused', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
                'event' => $this->event,
            ]);
            return;
        }

        $response->throw();
    }
}
