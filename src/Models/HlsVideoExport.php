<?php

namespace HlsVideos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * An MP4 copy of one HLS quality of a video (see Mp4ExportService).
 */
class HlsVideoExport extends Model
{
    const PENDING = 'pending';
    const PROCESSING = 'processing';
    const READY = 'ready';
    const FAILED = 'failed';
    const EXPIRED = 'expired';

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function video()
    {
        return $this->belongsTo(HlsVideo::class, 'hls_video_id');
    }

    public function getIsReadyAttribute(): bool
    {
        return $this->status === self::READY && $this->path && ! $this->is_expired;
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * A pending or processing row whose job has not reported back for too
     * long: the worker died without reaching failed(). It may be dispatched
     * again.
     */
    public function getIsStaleAttribute(): bool
    {
        return in_array($this->status, [self::PENDING, self::PROCESSING])
            && $this->updated_at->lt(now()->subMinutes(config('hls-videos.exports.stale_after_minutes', 120)));
    }

    /**
     * Short-lived signed link that downloads the file under $fileName.
     */
    public function temporaryDownloadUrl(string $fileName): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName);

        return Storage::disk($this->disk)->temporaryUrl(
            $this->path,
            now()->addMinutes(config('hls-videos.exports.url_ttl_minutes', 60)),
            [
                'ResponseContentType' => 'video/mp4',
                'ResponseContentDisposition' => "attachment; filename=\"{$ascii}\"; filename*=UTF-8''".rawurlencode($fileName),
            ]
        );
    }
}
