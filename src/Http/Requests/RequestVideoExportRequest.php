<?php

namespace HlsVideos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestVideoExportRequest extends FormRequest
{
    public function rules()
    {
        return [
            'quality' => 'required|string|max:20',
        ];
    }

    public function authorize()
    {
        return true;
    }
}
