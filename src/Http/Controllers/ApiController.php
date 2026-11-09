<?php

namespace  HlsVideos\Http\Controllers;

use Illuminate\Routing\Controller;

class ApiController extends Controller
{
    public function response($result = [], $message = 'Successfully', $status = 1)
    {
        $response = [
            'status' => $status,
            'message' => $message,
            'success' => true,
            'data'         => $result,
        ];

        return response()->json($response, 200);
    }

    public function error($error, $errorMessages = [], $code = 200)
    {
        $response = [
            'status' => 0,
            'success' => false,
            'message' => $error,
        ];

        if (!empty($errorMessages)) {
            $response['data'] = $errorMessages;
        }

        return response()->json($response, $code);
    }

    /** A library delete refused because lessons use some of its videos (422). */
    public function linkedVideosError(\HlsVideos\Exceptions\LinkedVideosException $e)
    {
        return $this->error(
            __('These videos are used in lessons, so they cannot be deleted from the library. Remove them from the lessons first.'),
            ['linked_videos' => $e->videos],
            422
        );
    }

    public function invalidData($error, $errorMessages = [], $code = 422)
    {
        $response = [
            'status' => 0,
            'message' => 'The given data was invalid.',
            'errors' => $error,
        ];

        if (!empty($errorMessages)) {
            $response['data'] = $errorMessages;
        }

        return response()->json($response, $code);
    }
}
