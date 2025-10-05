<?php

namespace HlsVideos\Http\Controllers;

use HlsVideos\Http\Requests\CopyMoveVideoRequest;
use HlsVideos\Http\Requests\RenameFolderVideoRequest;
use HlsVideos\Models\HlsFolder;
use HlsVideos\Models\HlsFolderVideo;
use Illuminate\Support\Facades\DB;

class HlsFolderVideoController extends ApiController
{
    public function __construct(
        protected HlsFolderVideo $hls_video_folder
    ) {}

    public function rename(RenameFolderVideoRequest $request)
    {
        $pivotRecord = $this->hls_video_folder->find($request->folder_video_id);
        $pivotRecord->update(['title' => $request->title]);

        return $this->response();
    }

    public function delete($id)
    {
        $pivotRecord = $this->hls_video_folder->find($id);
        if (!$pivotRecord)
            return $this->error('Video not found');

        $pivotRecord->delete();
        return $this->response();
    }

    public function move(CopyMoveVideoRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $pivotRecord = $this->hls_video_folder->find($request->folder_video_id);

            $pivotRecord->delete();
            $this->attachVideo($request->new_parent_id, $pivotRecord);

            return $this->response();
        });
    }

    public function copy(CopyMoveVideoRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $pivotRecord = $this->hls_video_folder->find($request->folder_video_id);

            $this->attachVideo($request->new_parent_id, $pivotRecord);

            return $this->response();
        });
    }

    /**
     * Attach video to a folder with a given pivot record's title.
     * Here we need pivot record to get the title and hls_video_id
     */
    private function attachVideo(int $folderId, HlsFolderVideo $pivotRecord): void
    {
        $folder = HlsFolder::find($folderId);

        $folder->videos()->attach(
            $pivotRecord->hls_video_id,
            ['title' => $pivotRecord->title]
        );
    }
}
