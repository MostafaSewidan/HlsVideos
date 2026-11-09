<?php

namespace HlsVideos\Exceptions;

/**
 * A library delete was refused because lessons still use some of its videos.
 * $videos: [['id' => ..., 'video' => title, 'used_in' => [label, ...]], ...]
 */
class LinkedVideosException extends \RuntimeException
{
    public function __construct(public array $videos)
    {
        parent::__construct('Some videos are used in lessons and cannot be deleted from the library.');
    }
}
