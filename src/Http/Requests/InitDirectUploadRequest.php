<?php

namespace HlsVideos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitDirectUploadRequest extends FormRequest
{
    public function rules()
    {
        return [
            'filename' => 'required|string|max:255',
            'size' => [
                'required',
                'integer',
                'min:1',
                'max:'.(int) config('hls-videos.direct_upload.max_file_size'),
            ],
            'content_type' => 'nullable|string|max:255',
            'folder_id' => 'nullable',
            'model_type' => 'nullable|string',
            'model_id' => 'nullable',
        ];
    }

    public function messages()
    {
        return [
            'size.max' => 'الملف أكبر من الحد المسموح به.',
        ];
    }

    /**
     * Route-level middleware (config: uploader_access_middleware) handles
     * authentication. Per-video authorization is delegated to the optional
     * hook at config('hls-videos.direct_upload.authorize'), which receives
     * ($request, $folderId) and returns a bool.
     */
    public function authorize()
    {
        $hook = config('hls-videos.direct_upload.authorize');

        if (! $hook) {
            return true;
        }

        return (bool) call_user_func($hook, $this, $this->input('folder_id'));
    }

    /**
     * Extension is taken from the uploaded name but never trusted verbatim:
     * it becomes part of a storage key.
     */
    public function safeExtension(): string
    {
        $extension = strtolower(pathinfo($this->input('filename'), PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', (string) $extension);

        return $extension !== '' ? substr($extension, 0, 10) : 'mp4';
    }
}
