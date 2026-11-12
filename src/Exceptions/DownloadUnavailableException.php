<?php

namespace HlsVideos\Exceptions;

use RuntimeException;

/**
 * A video quality that can't be downloaded offline right now. $reason is a
 * stable code for the API to map to a message for the student; the message
 * itself is for logs.
 */
class DownloadUnavailableException extends RuntimeException
{
    const NOT_READY = 'not_ready';       // still converting, or failed
    const NOT_HOT = 'not_hot';           // archived, or moving between storages
    const BUCKET = 'bucket';             // a bucket the download Worker can't read yet
    const NOT_FOUND = 'not_found';       // no vd.m3u8 for this quality
    const BROKEN = 'broken';             // the playlist names a segment that isn't on R2

    public string $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }
}
