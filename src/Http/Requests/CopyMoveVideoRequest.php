<?php

namespace  HlsVideos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CopyMoveVideoRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'folder_video_ids' => 'required|array',
            'folder_video_ids.*' => 'required|exists:hls_folder_video,id',
            'new_parent_id' => 'required|exists:hls_folders,id'
        ];
    }

    
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }
}
