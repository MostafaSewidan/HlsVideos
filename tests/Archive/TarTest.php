<?php

namespace HlsVideos\Tests\Archive;

use HlsVideos\Archive\ArchiveException;
use HlsVideos\Archive\Tar;
use PHPUnit\Framework\TestCase;

class TarTest extends TestCase
{
    use TempDirs;

    public function test_written_tar_is_read_by_system_tar_and_offsets_point_at_each_file(): void
    {
        $src = $this->tempDir();
        $files = [
            'index-0.ts' => random_bytes(1),
            'index-1.ts' => random_bytes(512),
            'index-2.ts' => random_bytes(513),
            'index-10.ts' => random_bytes(3 * 1024 * 1024 + 7),
            'index-11.ts' => '',
        ];
        foreach ($files as $name => $bytes) {
            file_put_contents("{$src}/{$name}", $bytes);
        }
        $tar = $this->tempDir().'/q.tar';

        $written = Tar::write($tar, $src, array_keys($files));
        Tar::verify($tar, $written);

        $this->assertSame(hash_file('sha256', $tar), $written['sha256']);
        $this->assertSame(filesize($tar), $written['size']);

        exec('tar -tf '.escapeshellarg($tar), $listed, $code);
        $this->assertSame(0, $code);
        $this->assertSame(array_keys($files), $listed);

        $out = $this->tempDir();
        exec('tar -xf '.escapeshellarg($tar).' -C '.escapeshellarg($out), $_, $code);
        $this->assertSame(0, $code);

        $raw = file_get_contents($tar);
        foreach ($written['files'] as $f) {
            $this->assertSame($files[$f['name']], file_get_contents("{$out}/{$f['name']}"));
            $this->assertSame($files[$f['name']], substr($raw, $f['offset'], $f['size']));
            $this->assertSame(hash('sha256', $files[$f['name']]), $f['sha256']);
        }
    }

    public function test_same_files_give_the_same_tar(): void
    {
        $src = $this->tempDir();
        file_put_contents("{$src}/a.ts", 'aaa');
        $one = Tar::write($this->tempDir().'/1.tar', $src, ['a.ts']);
        touch("{$src}/a.ts", time() - 3600);
        $two = Tar::write($this->tempDir().'/2.tar', $src, ['a.ts']);

        $this->assertSame($one['sha256'], $two['sha256']);
    }

    public function test_extract_writes_files_from_offsets_and_checks_sha(): void
    {
        $src = $this->tempDir();
        file_put_contents("{$src}/thumb.jpg", random_bytes(2000));
        mkdir("{$src}/720");
        file_put_contents("{$src}/720/vd.m3u8", "#EXTM3U\n");
        $tar = $this->tempDir().'/meta.tar';
        $written = Tar::write($tar, $src, ['720/vd.m3u8', 'thumb.jpg']);

        $out = $this->tempDir();
        Tar::extract($tar, $written['files'], $out);
        $this->assertFileEquals("{$src}/thumb.jpg", "{$out}/thumb.jpg");
        $this->assertFileEquals("{$src}/720/vd.m3u8", "{$out}/720/vd.m3u8");

        $bad = $written['files'];
        $bad[1]['sha256'] = str_repeat('0', 64);
        $this->expectException(ArchiveException::class);
        Tar::extract($tar, $bad, $this->tempDir());
    }

    public function test_extract_rejects_offsets_outside_the_tar_or_overlapping(): void
    {
        $src = $this->tempDir();
        file_put_contents("{$src}/a.ts", 'aaaa');
        file_put_contents("{$src}/b.ts", 'bbbb');
        $tar = $this->tempDir().'/x.tar';
        $written = Tar::write($tar, $src, ['a.ts', 'b.ts']);

        $overlap = $written['files'];
        $overlap[1]['offset'] = $overlap[0]['offset'];
        try {
            Tar::extract($tar, $overlap, $this->tempDir());
            $this->fail('overlapping offsets were accepted');
        } catch (ArchiveException $e) {
            $this->assertStringContainsString('offset', $e->getMessage());
        }

        $outside = $written['files'];
        $outside[1]['offset'] = 512 * 1000;
        $this->expectException(ArchiveException::class);
        Tar::extract($tar, $outside, $this->tempDir());
    }

    public function test_verify_catches_a_changed_byte(): void
    {
        $src = $this->tempDir();
        file_put_contents("{$src}/a.ts", random_bytes(4000));
        $tar = $this->tempDir().'/x.tar';
        $written = Tar::write($tar, $src, ['a.ts']);

        $h = fopen($tar, 'r+b');
        fseek($h, 700);
        fwrite($h, 'X');
        fclose($h);

        $this->expectException(ArchiveException::class);
        Tar::verify($tar, $written);
    }

    /** @dataProvider badNames */
    public function test_rejects_names_that_could_escape_the_target(string $name): void
    {
        $this->expectException(ArchiveException::class);
        Tar::checkName($name);
    }

    public static function badNames(): array
    {
        return [['../x.ts'], ['/etc/passwd'], ['a/../b'], ['a/b/c.ts'], ['..'], ['a b.ts'], [str_repeat('a', 101)], ['']];
    }
}
