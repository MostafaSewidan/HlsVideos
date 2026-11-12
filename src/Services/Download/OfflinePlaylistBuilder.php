<?php

namespace HlsVideos\Services\Download;

use RuntimeException;

/**
 * The playlist the app saves next to the downloaded file. Every segment
 * becomes a byte range of the one file ({quality}.ts), and the key URI becomes
 * "key", which the app's local server answers from the API on every play.
 *
 * Every other tag is kept as it is, the IV above all: the bytes on the phone are
 * the encrypted segments exactly as they are on R2.
 */
class OfflinePlaylistBuilder
{
    public function build(DownloadSourceData $source): string
    {
        $file = "{$source->quality}.ts";
        $out = [];
        $offset = 0;
        $index = 0;
        $hasVersion = false;

        foreach (preg_split('/\r?\n/', $source->playlist) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '#EXT-X-VERSION')) {
                // EXT-X-BYTERANGE needs version 4.
                $out[] = '#EXT-X-VERSION:4';
                $hasVersion = true;
            } elseif (str_starts_with($trimmed, '#EXT-X-KEY')) {
                $out[] = preg_replace('/URI="[^"]*"/', 'URI="key"', $trimmed);
            } elseif (! str_starts_with($trimmed, '#')) {
                if (! isset($source->segments[$index])) {
                    throw new RuntimeException("{$source->prefix}vd.m3u8 has more segments than the source");
                }
                $size = $source->segments[$index++][1];
                $out[] = "#EXT-X-BYTERANGE:{$size}@{$offset}";
                $out[] = $file;
                $offset += $size;
            } else {
                $out[] = $trimmed;
            }
        }

        if ($index !== $source->segmentCount() || $offset !== $source->totalSize) {
            throw new RuntimeException("Offline playlist for {$source->prefix} doesn't add up: {$index} segments, {$offset} bytes");
        }
        if (! $hasVersion) {
            array_splice($out, 1, 0, ['#EXT-X-VERSION:4']);
        }

        return implode("\n", $out)."\n";
    }
}
