<?php

namespace HlsVideos\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class FolderResource extends JsonResource
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
            'parent_id' => $this->parent_id,
            'title' => $this->title,
            'created_at'    => date('d-m-Y', strtotime($this->created_at)),
            'updated_at'    => date('d-m-Y', strtotime($this->updated_at)),
        ];
    }
}
