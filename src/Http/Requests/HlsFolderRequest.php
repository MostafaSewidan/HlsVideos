<?php

namespace  HlsVideos\Http\Requests;

use HlsVideos\Models\HlsFolder;
use Illuminate\Foundation\Http\FormRequest;

class HlsFolderRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'parent_id' => 'required|exists:hls_folders,id',
            'title' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (HlsFolder::hasDuplicateTitle($value, $this->parent_id)) {
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
