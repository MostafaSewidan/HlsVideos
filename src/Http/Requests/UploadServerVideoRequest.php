<?php

namespace HlsVideos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadServerVideoRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {

        return [
            'file' => 'required',
            'tenant_id' => 'required|exists:tenants,id',
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
