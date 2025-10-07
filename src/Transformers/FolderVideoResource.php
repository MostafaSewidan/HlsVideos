<?php

namespace HlsVideos\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class FolderVideoResource extends JsonResource
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
            'id' => $this->pivot->id,
            'video_id' => $this->id,
            'title' => $this->pivot?->title,
            'status' => $this->status,
            'original_extension' => $this->original_extension,
            'file_name' => $this->file_name,
            'original_file_name' => $this->original_file_name,
            'original_steam_quality' => $this->original_steam_quality,
            'stream_data' => $this->stream_data,

            'created_at'    => date('d-m-Y', strtotime($this->pivot->created_at)),
            'updated_at'    => date('d-m-Y', strtotime($this->pivot->updated_at)),
        ];
    }
}
