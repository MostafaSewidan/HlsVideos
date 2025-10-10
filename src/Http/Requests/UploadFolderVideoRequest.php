<?php

namespace  HlsVideos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadFolderVideoRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {

        return [
            'folder_id' => 'required|exists:hls_folders,id',
            'videos.*' => 'required|file|mimes:mp4,avi,mov,mkv,webm|max:2048'
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
