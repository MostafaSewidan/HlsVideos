<?php

namespace HlsVideos\Services\Download;

use RuntimeException;

/**
 * Signed links to the download Worker, one on Cloudflare and one through our
 * backup server. Both carry the same signature: the Worker sees the same path
 * from both, because nginx drops the "/dl" prefix.
 *
 *   sig = base64url(HMAC-SHA256(secret, "{path}|{u}|{exp}"))
 *
 * Must match the Worker's src/signature.ts.
 */
class DownloadUrlSigner
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('hls-videos.offline_download', []);
    }

    /**
     * @return array{url: string, backup_url: string, expires_at: string}
     */
    public function urls(DownloadSourceData $source, string $user): array
    {
        $path = self::path($source);
        $exp = now()->getTimestamp() + 60 * (int) ($this->config['url_ttl_minutes'] ?? 60);
        $query = '?'.http_build_query([
            'u' => $user,
            'exp' => $exp,
            'sig' => $this->sign($path, $user, $exp),
        ]);

        return [
            'url' => rtrim($this->required('url'), '/').$path.$query,
            'backup_url' => rtrim($this->required('backup_url'), '/').$path.$query,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $exp),
        ];
    }

    /** "/{bucket}/{media}/{video}/{quality}/{version}.ts" */
    public static function path(DownloadSourceData $source): string
    {
        return "/{$source->bucket}/{$source->prefix}{$source->version}.ts";
    }

    public function sign(string $path, string $user, int $exp): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $user)) {
            throw new RuntimeException('The download user id must be 1-64 of [A-Za-z0-9_-]');
        }
        $mac = hash_hmac('sha256', "{$path}|{$user}|{$exp}", $this->required('secret'), true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    private function required(string $key): string
    {
        $value = $this->config[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("hls-videos.offline_download.{$key} is not set");
        }

        return $value;
    }
}
