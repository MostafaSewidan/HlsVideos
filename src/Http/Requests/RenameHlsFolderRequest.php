<?php

namespace  HlsVideos\Http\Requests;

use HlsVideos\Models\HlsFolder;
use Illuminate\Foundation\Http\FormRequest;

class RenameHlsFolderRequest extends FormRequest
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
            'title' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    $folder = HlsFolder::find($this->folder_id);
                    if (HlsFolder::hasDuplicateTitle($value, $folder->parent_id, $folder->id)) {
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
