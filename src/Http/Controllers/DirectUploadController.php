<?php

namespace HlsVideos\Http\Controllers;

use HlsVideos\Http\Requests\InitDirectUploadRequest;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Services\DirectUploadService;
use HlsVideos\Services\VideoService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Signs a browser-driven multipart upload straight to R2.
 *
 * The contract with the client is deliberately narrow: it may send a video id
 * and a part number, nothing else. The storage key, the upload id and the
 * bucket are all read from the video row on the current tenant's connection,
 * so a client cannot redirect an upload at another path or another tenant.
 */
class DirectUploadController extends Controller
{
    protected ?DirectUploadService $uploads = null;

    public function __construct(protected VideoService $videoService)
    {
        //
    }

    /**
     * Resolved lazily rather than injected: its constructor throws when the
     * disk is not S3-compatible, and these routes are registered even on
     * installations still running the legacy driver, which must answer 404
     * rather than 500.
     */
    protected function uploads(): DirectUploadService
    {
        return $this->uploads ??= app(DirectUploadService::class);
    }

    public function init(InitDirectUploadRequest $request)
    {
        $this->assertDriverEnabled();

        $model = null;
        if ($request->model_type && $request->model_id) {
            $model = $request->model_type::find($request->model_id);
        }

        $video = DB::transaction(function () use ($request, $model) {
            return $this->videoService->createPendingVideo(
                originalFileName: $request->input('filename'),
                extension: $request->safeExtension(),
                model: $model,
                folderId: $request->input('folder_id'),
                size: (int) $request->input('size')
            );
        });

        $uploadId = null;

        try {
            $uploadId = $this->uploads()->createMultipartUpload(
                $video->original_key,
                $request->input('content_type') ?: 'video/mp4'
            );

            // Persisting the id belongs inside the try: if this write fails the
            // upload already exists on R2 with nothing pointing at it, so it has
            // to be aborted here rather than left for the lifecycle rule.
            $video->forceFill(['r2_upload_id' => $uploadId])->save();

        } catch (\Throwable $e) {
            if ($uploadId) {
                $this->uploads()->abortMultipartUpload($video->original_key, $uploadId);
            }

            $video->forceFill(['status' => HlsVideo::UPLOAD_FAILED])->save();

            \Log::error("createMultipartUpload failed for video {$video->id}: ".$e->getMessage());

            return response()->json(['message' => 'تعذّر بدء الرفع، حاول مرة أخرى.'], 500);
        }

        return response()->json([
            'video_id' => $video->id,
            'key' => $video->original_key,
            'uploadId' => $uploadId,
            'part_size' => (int) config('hls-videos.direct_upload.part_size'),
        ]);
    }

    public function signPart(Request $request, $videoId)
    {
        $this->assertDriverEnabled();

        $video = $this->resolveUploadableVideo($videoId);

        $partNumber = (int) $request->input('partNumber', $request->input('part_number'));

        // 10,000 is the S3/R2 ceiling; anything outside it is a malformed or
        // hostile client, not a retry worth signing.
        if ($partNumber < 1 || $partNumber > 10000) {
            abort(422, 'Invalid part number.');
        }

        return response()->json(
            $this->uploads()->signPart($video->original_key, $video->r2_upload_id, $partNumber)
        );
    }

    public function parts($videoId)
    {
        $this->assertDriverEnabled();

        $video = $this->resolveUploadableVideo($videoId);

        return response()->json(
            $this->uploads()->listParts($video->original_key, $video->r2_upload_id)
        );
    }

    public function complete(Request $request, $videoId)
    {
        $this->assertDriverEnabled();

        $video = $this->findTenantVideo($videoId);

        // Called twice -- a retry, a double click, a reconcile race. The first
        // call did the work; say so and do nothing.
        if ($video->status !== HlsVideo::PENDING_UPLOAD) {
            return $this->optionsResponse($video);
        }

        $parts = $request->input('parts', []);

        if (! is_array($parts) || empty($parts)) {
            abort(422, 'No parts supplied.');
        }

        $this->assertOwnedKey($video);

        try {
            $this->uploads()->completeMultipartUpload(
                $video->original_key,
                $video->r2_upload_id,
                $parts
            );
        } catch (\Throwable $e) {
            \Log::error("completeMultipartUpload failed for video {$video->id}: ".$e->getMessage());

            return response()->json(['message' => 'تعذّر إنهاء الرفع.'], 500);
        }

        $this->markUploaded($video);

        return $this->optionsResponse($video->fresh());
    }

    public function abort($videoId)
    {
        $this->assertDriverEnabled();

        $video = $this->findTenantVideo($videoId);

        if ($video->status !== HlsVideo::PENDING_UPLOAD) {
            abort(409, 'Upload is no longer in progress.');
        }

        $this->assertOwnedKey($video);

        if ($video->r2_upload_id) {
            $this->uploads()->abortMultipartUpload($video->original_key, $video->r2_upload_id);
        }

        $video->forceFill([
            'status' => HlsVideo::UPLOAD_FAILED,
            'r2_upload_id' => null,
        ])->save();

        return response()->json(['status' => true]);
    }

    /**
     * Confirms the object really landed, records its size, then hands the video
     * to the encoder. Shared by the client callback and the reconcile command
     * so both take exactly the same path.
     */
    public static function markUploaded(HlsVideo $video): void
    {
        $uploads = app(DirectUploadService::class);
        $head = $uploads->headObject($video->original_key);

        $video->forceFill([
            'status' => HlsVideo::UPLOADED,
            'r2_upload_id' => null,
            'upload_size' => $head['size'] ?? $video->upload_size,
        ])->save();

        (new VideoService)->startProcessing($video);
    }

    protected function resolveUploadableVideo($videoId): HlsVideo
    {
        $video = $this->findTenantVideo($videoId);

        // Signing is only legitimate while an upload is genuinely in flight.
        // Without this, anyone holding an id could overwrite a published video.
        if ($video->status !== HlsVideo::PENDING_UPLOAD || ! $video->r2_upload_id) {
            abort(409, 'This video is not accepting uploads.');
        }

        $this->assertOwnedKey($video);

        return $video;
    }

    protected function findTenantVideo($videoId): HlsVideo
    {
        // Runs on the current tenant's connection, so another tenant's id
        // simply does not resolve.
        return HlsVideo::whereKey($videoId)->firstOrFail();
    }

    /**
     * Belt and braces on top of database isolation: if tenant resolution ever
     * misfires, this still refuses to sign a write outside the current
     * tenant's own prefix.
     */
    protected function assertOwnedKey(HlsVideo $video): void
    {
        $expected = VideoService::tenantOriginalPrefix();

        if (! $video->original_key || strpos($video->original_key, $expected) !== 0) {
            \Log::warning("Rejected upload signing outside tenant prefix", [
                'video' => $video->id,
                'key' => $video->original_key,
                'expected_prefix' => $expected,
            ]);

            abort(403);
        }
    }

    protected function assertDriverEnabled(): void
    {
        if (config('hls-videos.upload_driver') !== 'direct') {
            abort(404);
        }
    }

    /**
     * Same payload shape as HlsVideoController::getOptions so the existing
     * front-end card rendering works unchanged.
     */
    protected function optionsResponse(HlsVideo $video)
    {
        return response()->json([
            'html' => view("hls-videos::components.video-options", ['video' => $video])->render(),
            'build_uploader' => false,
            'is_ready' => $video->is_ready,
            'video_source' => $video->is_ready
                ? route(config('hls-videos.access_route_stream'), [$video->id])
                : '',
            'video_id' => $video->id,
        ]);
    }
}
