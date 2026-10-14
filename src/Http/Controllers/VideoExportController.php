<?php

namespace HlsVideos\Http\Controllers;

use HlsVideos\Http\Requests\RequestVideoExportRequest;
use HlsVideos\Models\HlsVideoExport;
use HlsVideos\Repositories\HlsVideoExportRepository;
use HlsVideos\Services\Mp4ExportService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * MP4 download of a library video: list its qualities with their export
 * state, request an export, and download a ready one.
 */
class VideoExportController extends Controller
{
    public function __construct(
        protected HlsVideoExportRepository $exports,
        protected Mp4ExportService $service
    ) {
    }

    public function index(string $videoId)
    {
        $video = $this->exports->findReadyVideo($videoId);
        abort_unless($video, 404);

        $exports = $this->exports->exportsByQuality($video, Mp4ExportService::disk());

        return response()->json([
            'qualities' => $this->exports->qualities($video)->map(fn ($quality) => [
                'quality' => $quality,
                'export' => isset($exports[$quality]) ? $this->present($exports[$quality]) : null,
            ]),
        ]);
    }

    public function store(RequestVideoExportRequest $request, string $videoId)
    {
        $video = $this->exports->findReadyVideo($videoId);
        abort_unless($video, 404);
        abort_unless($this->exports->qualities($video)->contains($request->quality), 422);

        return response()->json([
            'export' => $this->present($this->service->request($video, $request->quality)),
        ]);
    }

    public function download(Request $request, int $exportId)
    {
        $export = $this->exports->find($exportId);
        abort_unless($export && $export->is_ready, 404);

        $title = $this->exports->displayTitle($export->video, $request->integer('folder_video_id') ?: null);

        return redirect()->away($export->temporaryDownloadUrl("{$title} - {$export->quality}p.mp4"));
    }

    /**
     * Status as the dashboard shows it: a ready row past its expiry, or a
     * running one whose worker died, reads as needing a new request.
     */
    protected function present(HlsVideoExport $export): array
    {
        $status = match (true) {
            $export->status === HlsVideoExport::READY && $export->is_expired => HlsVideoExport::EXPIRED,
            $export->is_stale => HlsVideoExport::FAILED,
            default => $export->status,
        };

        return [
            'id' => $export->id,
            'quality' => $export->quality,
            'status' => $status,
            'size' => $export->size,
            'expires_at' => $export->expires_at?->toIso8601String(),
            'download_url' => $status === HlsVideoExport::READY
                ? route('hls.videos.exports.download', $export->id)
                : null,
        ];
    }
}
