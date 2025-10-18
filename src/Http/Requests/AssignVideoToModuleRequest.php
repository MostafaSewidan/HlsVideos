<?php

namespace HlsVideos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignVideoToModuleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {

        return [
            'video_id' => 'required|exists:hls_videos,id',
            'model_type' => 'required|string',
            'model_id' => 'required',
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
