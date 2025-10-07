<?php

namespace HlsVideos\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class LiteFolderVideoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'video_id' => $this->hls_video_id,
            'folder_id' => $this->folder_id,
            'title' => $this->title,
            'created_at'    => date('d-m-Y', strtotime($this->created_at)),
            'updated_at'    => date('d-m-Y', strtotime($this->updated_at)),
        ];
    }
}
