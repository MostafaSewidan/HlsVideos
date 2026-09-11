<?php

namespace HlsVideos\Services;

use Aws\S3\S3Client;

/**
 * Thin wrapper around the S3 multipart API for browser-driven uploads.
 *
 * Everything here deals in raw keys. Deciding WHICH key a caller is allowed to
 * touch is the controller's job -- this class never reads anything from the
 * request.
 */
class DirectUploadService
{
    protected string $diskName;

    protected array $diskConfig;

    protected ?S3Client $client = null;

    public function __construct(?string $diskName = null)
    {
        $this->diskName = $diskName ?: config('hls-videos.uploaded_videos_disk', 'r2');
        $this->diskConfig = config("filesystems.disks.{$this->diskName}") ?? [];

        if (empty($this->diskConfig['bucket'])) {
            throw new \RuntimeException(
                "Disk [{$this->diskName}] is not configured as an S3-compatible disk; direct upload needs a bucket."
            );
        }
    }

    public function bucket(): string
    {
        return $this->diskConfig['bucket'];
    }

    /**
     * Builds the S3 client straight from the disk configuration rather than
     * pulling it off the Flysystem adapter, because the way to reach that
     * adapter differs between Laravel 7/8 (Flysystem 1) and Laravel 9+
     * (Flysystem 3), and this package supports both.
     */
    public function client(): S3Client
    {
        if ($this->client) {
            return $this->client;
        }

        $args = [
            'version' => 'latest',
            'region' => $this->diskConfig['region'] ?? 'auto',
            'endpoint' => $this->diskConfig['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($this->diskConfig['use_path_style_endpoint'] ?? false),
            'credentials' => [
                'key' => $this->diskConfig['key'] ?? null,
                'secret' => $this->diskConfig['secret'] ?? null,
            ],
        ];

        // Recent versions of aws/aws-sdk-php compute request checksums by
        // default, which adds x-amz-sdk-checksum-algorithm to the signature.
        // The browser never sends that header, so every presigned PUT would
        // fail with SignatureDoesNotMatch. Older SDKs reject the option, hence
        // the fallback.
        try {
            $this->client = new S3Client($args + ['request_checksum_calculation' => 'when_required']);
        } catch (\InvalidArgumentException $e) {
            $this->client = new S3Client($args);
        }

        return $this->client;
    }

    public function createMultipartUpload(string $key, ?string $contentType = null): string
    {
        $result = $this->client()->createMultipartUpload(array_filter([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'ContentType' => $contentType,
        ]));

        return $result['UploadId'];
    }

    /**
     * Presigned URL for a single UploadPart call.
     *
     * Neither Body nor ContentLength are set: including them would bake those
     * headers into the signature, and the browser sends its own.
     */
    public function signPart(string $key, string $uploadId, int $partNumber, ?int $ttlMinutes = null): array
    {
        $ttlMinutes = $ttlMinutes ?: (int) config('hls-videos.direct_upload.url_ttl_minutes', 30);

        $command = $this->client()->getCommand('UploadPart', [
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        $request = $this->client()->createPresignedRequest($command, "+{$ttlMinutes} minutes");

        return [
            'url' => (string) $request->getUri(),
            'expires_at' => now()->addMinutes($ttlMinutes)->toIso8601String(),
        ];
    }

    /**
     * Parts already stored by R2, in the shape Uppy's listParts expects.
     */
    public function listParts(string $key, string $uploadId): array
    {
        $parts = [];
        $marker = 0;

        do {
            $result = $this->client()->listParts([
                'Bucket' => $this->bucket(),
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumberMarker' => $marker,
            ]);

            foreach ($result['Parts'] ?? [] as $part) {
                $parts[] = [
                    'PartNumber' => (int) $part['PartNumber'],
                    'Size' => (int) $part['Size'],
                    'ETag' => $part['ETag'],
                ];
            }

            $marker = (int) ($result['NextPartNumberMarker'] ?? 0);
        } while (! empty($result['IsTruncated']));

        return $parts;
    }

    /**
     * @param  array  $parts  list of ['PartNumber' => int, 'ETag' => string]
     */
    public function completeMultipartUpload(string $key, string $uploadId, array $parts): array
    {
        $normalised = [];

        foreach ($parts as $part) {
            $number = (int) ($part['PartNumber'] ?? $part['partNumber'] ?? 0);
            $etag = $part['ETag'] ?? $part['etag'] ?? null;

            if ($number < 1 || ! $etag) {
                throw new \InvalidArgumentException('Malformed part entry in completeMultipartUpload.');
            }

            $normalised[] = ['PartNumber' => $number, 'ETag' => $etag];
        }

        usort($normalised, fn ($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);

        $result = $this->client()->completeMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $normalised],
        ]);

        return [
            'location' => $result['Location'] ?? null,
            'etag' => $result['ETag'] ?? null,
        ];
    }

    public function abortMultipartUpload(string $key, string $uploadId): void
    {
        try {
            $this->client()->abortMultipartUpload([
                'Bucket' => $this->bucket(),
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
        } catch (\Throwable $e) {
            // Already gone, or expired by the bucket lifecycle rule. Either way
            // there is nothing left to abort.
            \Log::info("abortMultipartUpload ignored for {$key}: ".$e->getMessage());
        }
    }

    /**
     * @return array{size:int,etag:?string}|null  null when the object is absent
     */
    public function headObject(string $key): ?array
    {
        try {
            $result = $this->client()->headObject([
                'Bucket' => $this->bucket(),
                'Key' => $key,
            ]);

            return [
                'size' => (int) $result['ContentLength'],
                'etag' => $result['ETag'] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
