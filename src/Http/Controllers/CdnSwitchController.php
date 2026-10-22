<?php

namespace HlsVideos\Http\Controllers;

use HlsVideos\Jobs\ReportCdnSwitchJob;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * The player calls this once each time it moves a viewer from one CDN to the
 * other. Nothing is stored here: the event is handed to a queued job that
 * sends it to steps_monitoring, so this request stays fast and never fails
 * the viewer's page.
 */
class CdnSwitchController extends Controller
{
    public function store(Request $request)
    {
        if (! config('hls-videos.cdn_fallback.report.enabled', true)) {
            return response()->noContent();
        }

        $data = $request->validate([
            'video_id' => ['required', 'integer', 'min:1'],
            'from_cdn' => ['required', 'in:primary,backup'],
            'to_cdn' => ['required', 'in:primary,backup', 'different:from_cdn'],
            'reason' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'http_status' => ['nullable', 'integer', 'min:0', 'max:999'],
            'failed_url' => ['nullable', 'string'],
            'player' => ['required', 'in:hlsjs,native,mp4'],
            'position_seconds' => ['nullable', 'numeric', 'min:0'],
        ]);

        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        $hosts = [
            'primary' => config('hls-videos.storage_stream_domain'),
            'backup' => config('hls-videos.cdn_fallback.backup_domain'),
        ];

        $event = [
            'tenant_id' => $tenant?->id,
            'tenant' => $tenant?->subdomain,
            'video_id' => (int) $data['video_id'],
            'user_id' => $this->userId(),
            'from_cdn' => $data['from_cdn'],
            'to_cdn' => $data['to_cdn'],
            'from_host' => $hosts[$data['from_cdn']],
            'to_host' => $hosts[$data['to_cdn']],
            'reason' => $data['reason'],
            'http_status' => isset($data['http_status']) ? (int) $data['http_status'] : null,
            'failed_url' => isset($data['failed_url']) ? Str::limit($data['failed_url'], 2000, '') : null,
            'player' => $data['player'],
            'position_seconds' => isset($data['position_seconds']) ? round((float) $data['position_seconds'], 2) : null,
            'user_agent' => Str::limit((string) $request->userAgent(), 512, '') ?: null,
            'ip' => $request->ip(),
            'occurred_at' => now()->toIso8601String(),
        ];

        ReportCdnSwitchJob::dispatch($event)
            ->onQueue(config('hls-videos.cdn_fallback.report.queue', 'default'));

        return response()->noContent();
    }

    private function userId(): ?int
    {
        foreach ((array) config('hls-videos.cdn_fallback.report.user_guards', []) as $guard) {
            try {
                $id = auth($guard)->id();
            } catch (\Throwable $e) {
                continue;
            }

            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }
}
