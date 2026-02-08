<?php
namespace HlsVideos\Http\Controllers;

use HlsVideos\Http\Requests\UploadServerVideoRequest;
use HlsVideos\Http\Requests\{UploadVideoRequest, AssignVideoToModuleRequest};
use HlsVideos\Models\HlsFolder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use HlsVideos\Services\VideoService;

class HlsVideoController extends Controller
{
    public function __construct(protected VideoService $videoService)
    {
        //
    }

    public function assignVideoToModule(AssignVideoToModuleRequest $request)
    {
        try {
            $model = null;
            if ($request->model_type && $request->model_id)
                $model = $request->model_type::find($request->model_id);

            if ($model)
                $model->hlsVideos()->attach([$request->video_id]);

            return $this->getOptions($request->video_id);

        } catch (\PDOException $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }

    public function uploadVideo(UploadVideoRequest $request)
    {
        try {
            $model = null;
            if ($request->model_type && $request->model_id)
                $model = $request->model_type::find($request->model_id);

            $receiver = $this->videoService->receiveVideo($request, $model, $request->folder_id);

            if ($receiver && $receiver['status']) {
                if (isset($receiver['video']))
                    return $this->getOptions($receiver['video']->id);
                else
                    return response()->json([$receiver]);
            }

            return Response()->json([false, __('apps::dashboard.messages.failed')], 500);
        } catch (\PDOException $e) {
            return Response()->json([false, $e->errorInfo[2]], 500);
        }
    }

    public function uploadFromServer(UploadServerVideoRequest $request, $videoId)
    {
        try {
            $authToken = $request->header('Authorization');
            if ($authToken != config('hls-videos.steps_encoder_token')) {
                return Response()->json([false, __('apps::dashboard.messages.failed')], 401);
            }

            $tenant = config('hls-videos.tenant_model')::findOrFail($request->tenant_id);
            $tenant->makeCurrent();
            $this->videoService->receiveFromServer($request, $videoId);


            return response()->json(['message' => 'Video uploaded successfully']);

        } catch (\PDOException $e) {
            logger("uploadFromServer error", [
                'error' => $e->getMessage(),
                'videoId' => $videoId,
                'request' => $request->all()
            ]);
            return Response()->json([false, $e->errorInfo[2]], 500);
        }
    }

    public function list(Request $request)
    {

    }

    public function getOptions($id = null)
    {
        $video = $id ? VideoService::findById($id) : null;

        return response()->json([
            'html' => view("hls-videos::components.video-options", compact('video'))->render(),
            'build_uploader' => ! $video,
            'is_ready' => $video?->is_ready,
            'video_source' => $video?->is_ready ? route(config('hls-videos.access_route_stream'), [$video->id]) : '',
            'video_id' => $video?->id
        ]);
    }

    public function deleteVideo(Request $request, $id)
    {
        try {
            VideoService::deleteVideo($request, $id);
            return $this->getOptions();
        } catch (\PDOException $e) {
            return Response()->json([false, $e->errorInfo[2]], 500);
        }
    }
}