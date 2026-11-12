<?php

namespace HlsVideos\Tests\Download;

use HlsVideos\Services\Download\DownloadSourceData;
use HlsVideos\Services\Download\OfflinePlaylistBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OfflinePlaylistBuilderTest extends TestCase
{
    const PLAYLIST = "#EXTM3U\r\n#EXT-X-VERSION:3\r\n#EXT-X-TARGETDURATION:11\r\n#EXT-X-MEDIA-SEQUENCE:0\r\n#EXT-X-PLAYLIST-TYPE:VOD\r\n"
        ."#EXT-X-KEY:METHOD=AES-128,URI=\"https://total.stepsio.com/api/vd/uuid/stream/720/secret.key\",IV=0x7fbb5a6b65d56a230b95bb975ee560c6\r\n"
        ."#EXTINF:11.211200,\r\nindex-000.ts\r\n#EXTINF:9.609600,\r\nindex-001.ts\r\n#EXT-X-ENDLIST\r\n";

    private function source(string $playlist = self::PLAYLIST, array $segments = [['index-000.ts', 891888, 11.2112], ['index-001.ts', 758592, 9.6096]]): DownloadSourceData
    {
        return new DownloadSourceData('legacy', 'total/uuid/720/', '720', 'f6f1bf1e97abbea5', $segments,
            array_sum(array_column($segments, 1)), 20.8208, $playlist);
    }

    public function test_every_segment_becomes_a_byte_range_of_one_file(): void
    {
        $expected = <<<'M3U8'
#EXTM3U
#EXT-X-VERSION:4
#EXT-X-TARGETDURATION:11
#EXT-X-MEDIA-SEQUENCE:0
#EXT-X-PLAYLIST-TYPE:VOD
#EXT-X-KEY:METHOD=AES-128,URI="key",IV=0x7fbb5a6b65d56a230b95bb975ee560c6
#EXTINF:11.211200,
#EXT-X-BYTERANGE:891888@0
720.ts
#EXTINF:9.609600,
#EXT-X-BYTERANGE:758592@891888
720.ts
#EXT-X-ENDLIST

M3U8;

        $this->assertSame($expected, (new OfflinePlaylistBuilder)->build($this->source()));
    }

    public function test_adds_version_4_when_the_playlist_has_none(): void
    {
        $playlist = str_replace("#EXT-X-VERSION:3\r\n", '', self::PLAYLIST);
        $lines = explode("\n", (new OfflinePlaylistBuilder)->build($this->source($playlist)));

        $this->assertSame(['#EXTM3U', '#EXT-X-VERSION:4'], array_slice($lines, 0, 2));
    }

    public function test_offsets_cover_the_whole_file(): void
    {
        $segments = [];
        for ($i = 0; $i < 50; $i++) {
            $segments[] = [sprintf('index-%03d.ts', $i), 1000 + $i * 37, 10.0];
        }
        $playlist = "#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI=\"secret.key\",IV=0x01\n";
        foreach ($segments as [$name]) {
            $playlist .= "#EXTINF:10.0,\n{$name}\n";
        }

        $out = (new OfflinePlaylistBuilder)->build($this->source($playlist, $segments));
        preg_match_all('/#EXT-X-BYTERANGE:(\d+)@(\d+)/', $out, $m, PREG_SET_ORDER);

        $next = 0;
        foreach ($m as $i => [, $size, $offset]) {
            $this->assertSame($next, (int) $offset);
            $this->assertSame($segments[$i][1], (int) $size);
            $next += (int) $size;
        }
        $this->assertSame(array_sum(array_column($segments, 1)), $next);
        $this->assertStringContainsString('IV=0x01', $out);
    }

    public function test_refuses_a_playlist_that_does_not_match_the_source(): void
    {
        $this->expectException(RuntimeException::class);
        (new OfflinePlaylistBuilder)->build($this->source(self::PLAYLIST, [['index-000.ts', 891888, 11.2112]]));
    }
}
