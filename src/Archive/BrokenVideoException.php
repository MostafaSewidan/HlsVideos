<?php

namespace HlsVideos\Archive;

/**
 * The video's files on R2 are incomplete: a playlist points at a segment that is
 * missing or empty. Such a video is never archived.
 */
class BrokenVideoException extends ArchiveException
{
    public function __construct(public array $missing)
    {
        parent::__construct('Playlists point at segments missing on R2: '.implode(', ', array_slice($missing, 0, 10))
            .(count($missing) > 10 ? ' (+'.(count($missing) - 10).' more)' : ''));
    }
}
