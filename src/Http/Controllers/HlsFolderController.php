<?php

namespace HlsVideos\Http\Controllers;

use HlsVideos\Http\Requests\HlsFolderRequest;
use HlsVideos\Http\Requests\MoveFolderRequest;
use HlsVideos\Http\Requests\RenameHlsFolderRequest;
use Illuminate\Http\Request;
use HlsVideos\Models\HlsFolder;
use HlsVideos\Models\HlsFolderVideo;
use HlsVideos\Transformers\FolderBreadcrumbResource;
use HlsVideos\Transformers\FolderResource;
use HlsVideos\Transformers\FolderVideoResource;
use HlsVideos\Transformers\LiteFolderResource;

class HlsFolderController extends ApiController
{
    public function __construct(protected HlsFolder $hls_folder) {}

    public function list(Request $request)
    {
        if ($request->id)
            $folder = $this->hls_folder->withRelations()->find($request->id);
        else
            $folder = $this->hls_folder->withRelations()->masters()->first();

        $videos = $folder->videos()->orderByDesc('created_at')->paginate(30);
        return $this->response([
            'folder' => new FolderResource($folder),
            'folders' => LiteFolderResource::collection($folder->relatedFolders),
            'videos' => FolderVideoResource::collection($videos)->response()->getData(true),
            'breadcrumb' => FolderBreadcrumbResource::collection($folder->breadcrumb),
        ]);
    }

    public function create(HlsFolderRequest $request)
    {
        $parent = $this->hls_folder->find($request->parent_id);

        $folder = $parent->relatedFolders()->create(['title' => $request->title]);
        return $this->response(['folder' => new LiteFolderResource($folder)]);
    }

    public function rename(RenameHlsFolderRequest $request)
    {
        $folder = $this->hls_folder->find($request->folder_id);
        $folder->update(['title' => $request->title]);

        return $this->response(['folder' => new LiteFolderResource($folder)]);
    }

    public function delete(Request $request)
    {
        $this->hls_folder->whereIn('id', (array)$request->ids)->delete();

        return $this->response();
    }

    public function move(MoveFolderRequest $request)
    {
        foreach ($request->folder_ids as $id) {
            $folder = $this->hls_folder->find($id);
            $folder->update(['parent_id' => $request->new_parent_id]);
        }

        return $this->response(['folder' => new LiteFolderResource($folder)]);
    }

    public function search(Request $request)
    {
        $search = trim($request->get('search'));

        if (!$search) {
            return $this->response(['results' => []]);
        }

        $folders = $this->hls_folder
            ->where('title', 'LIKE', "%{$search}%")
            ->with('parent')
            ->get()
            ->map(function ($folder) {
                return [
                    'folder' => new LiteFolderResource($folder),
                    'breadcrumb' => FolderBreadcrumbResource::collection($folder->breadcrumb),
                ];
            });

        //TODO add pagination
        $videos = HlsFolderVideo::with(['folder', 'video'])
            ->where('title', 'LIKE', "%{$search}%")
            ->get()
            ->map(function ($pivot) {
                $video = $pivot->video;
                $video->setRelation('pivot', $pivot);

                return [
                    'video' => new FolderVideoResource($video),
                    'breadcrumb' => FolderBreadcrumbResource::collection($pivot->folder->breadcrumb),
                ];
            });

        return $this->response(['folders' => $folders, 'videos' => $videos]);
    }
}
