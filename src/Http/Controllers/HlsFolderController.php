<?php

namespace HlsVideos\Http\Controllers;

use HlsVideos\Http\Requests\HlsFolderRequest;
use HlsVideos\Http\Requests\MoveFolderRequest;
use HlsVideos\Http\Requests\RenameHlsFolderRequest;
use Illuminate\Http\Request;
use HlsVideos\Models\HlsFolder;
use HlsVideos\Transformers\FolderBreadcrumbResource;
use HlsVideos\Transformers\FolderResource;
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

        $folder = $folder ? new FolderResource($folder) : null;
        $breadcrumb = $folder ? FolderBreadcrumbResource::collection($folder->breadcrumb) : null;
        
        return $this->response(['folder' => $folder, 'breadcrumb' => $breadcrumb]);
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

    public function delete($id)
    {
        $folder = $this->hls_folder->find($id);
        if (!$folder)
            return $this->error('Folder not found');

        $folder->delete();
        return $this->response();
    }

    public function move(MoveFolderRequest $request)
    {
        $folder = $this->hls_folder->find($request->folder_id);
        $folder->update(['parent_id' => $request->new_parent_id]);

        return $this->response();
    }
}
