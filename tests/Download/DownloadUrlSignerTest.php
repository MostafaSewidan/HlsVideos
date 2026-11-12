<?php

namespace HlsVideos\Tests\Download;

use HlsVideos\Services\Download\DownloadSourceData;
use HlsVideos\Services\Download\DownloadUrlSigner;
use Illuminate\Support\Carbon;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class DownloadUrlSignerTest extends TestCase
{
    private function signer(array $over = []): DownloadUrlSigner
    {
        return new DownloadUrlSigner($over + [
            'url' => 'https://dl.stepsio-stream.org',
            'backup_url' => 'https://streamer.stepsio.com/dl/',
            'secret' => 'test-secret',
            'url_ttl_minutes' => 60,
        ]);
    }

    public function test_signature_matches_the_worker(): void
    {
        // Same inputs and output as the Worker's test/signature.test.ts.
        $sig = $this->signer()->sign('/legacy/media/9d1c-uuid/720/a1b2c3d4e5f60718.ts', '42', 1800003600);

        $this->assertSame('tWGNkVfOW-vH6X7TBNig080lYR5Ve38x5Kugnp1wEJo', $sig);
    }

    public function test_two_links_with_the_same_path_and_signature(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(1800000000));
        $source = new DownloadSourceData('legacy', 'total/uuid/720/', '720', '9da597e22ff052a0', [], 0, 0.0, '');

        $urls = $this->signer()->urls($source, '42');

        $query = '?u=42&exp=1800003600&sig='.$this->signer()->sign('/legacy/total/uuid/720/9da597e22ff052a0.ts', '42', 1800003600);
        $this->assertSame('https://dl.stepsio-stream.org/legacy/total/uuid/720/9da597e22ff052a0.ts'.$query, $urls['url']);
        $this->assertSame('https://streamer.stepsio.com/dl/legacy/total/uuid/720/9da597e22ff052a0.ts'.$query, $urls['backup_url']);
        $this->assertSame('2027-01-15T09:00:00Z', $urls['expires_at']);
    }

    public function test_refuses_without_a_secret(): void
    {
        $this->expectException(RuntimeException::class);
        $this->signer(['secret' => ''])->sign('/a.ts', '1', 1);
    }

    public function test_refuses_a_user_id_the_worker_would_reject(): void
    {
        $this->expectException(RuntimeException::class);
        $this->signer()->sign('/a.ts', 'a b', 1);
    }
}
