<?php

namespace  HlsVideos\Http\Requests;

use HlsVideos\Models\HlsFolderVideo;
use Illuminate\Foundation\Http\FormRequest;

class RenameFolderVideoRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'folder_video_id' => 'required|exists:hls_folder_video,id',
            'title' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $video = HlsFolderVideo::find($this->folder_video_id);
                    if (HlsFolderVideo::hasDuplicateTitle($value, $video->folder_id, $video->id)) {
                        $fail(__('The same name is already used in this location'));
                    }
                },
            ],
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
