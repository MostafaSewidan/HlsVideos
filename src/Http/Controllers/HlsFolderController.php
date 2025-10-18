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
    protected $repository;

    public function __construct(protected HlsFolder $hls_folder)
    {
        $this->repository = config('hls-videos.repositories.hls_folder');
    }

    public function list(Request $request)
    {
        if ($request->id)
            $folder = $this->hls_folder->find($request->id);
        else
            $folder = $this->repository::masters($this->hls_folder)->first();

        $folders = $folder?->relatedFolders ?? $this->repository::mainSharedFolders($this->hls_folder)->get();
        $videos = $folder ? $folder->videos()->orderByDesc('created_at')->paginate($request->paginate ?? 30) : collect([]);
        $breadcrumb = $folder?->breadcrumb ?? collect([]);

        return $this->response([
            'folder' => $folder ? new FolderResource($folder) : null, // if null means shared only
            'folders' => LiteFolderResource::collection($folders),
            'videos' => FolderVideoResource::collection($videos)->response()->getData(true),
            'breadcrumb' => FolderBreadcrumbResource::collection($breadcrumb),
            'is_shared_mode' => $this->repository::isSharedFolders(),
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

        $videosPaginator = HlsFolderVideo::with(['folder', 'video'])
            ->where('title', 'LIKE', "%{$search}%")
            ->orderByDesc('created_at')
            ->paginate(10)
            ->appends(['search' => $search]); // or any page size you prefer
            
        $videos = $videosPaginator->getCollection()->map(function ($pivot) {
            $video = $pivot->video;
            $video->setRelation('pivot', $pivot);

            return [
                'video' => new FolderVideoResource($video),
                'breadcrumb' => FolderBreadcrumbResource::collection($pivot->folder->breadcrumb),
            ];
        });

        // Replace the collection with the transformed data
        $videosPaginator->setCollection($videos);

        return $this->response(['folders' => $folders, 'videos' => $videosPaginator->toArray()]);
    }
}
