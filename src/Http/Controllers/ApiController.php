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
