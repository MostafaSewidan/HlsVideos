<?php
namespace  HlsVideos\Http\Controllers;

use HlsVideos\Http\Requests\UploadVideoRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use HlsVideos\Services\VideoService;



class HlsVideoController extends Controller
{
    public function __construct(protected VideoService $videoService) {
        //
        
    }

    public function uploadVideo(UploadVideoRequest $request)
    {
        try {
            $model = null;
            if($request->model_type && $request->model_id)
                $model = $request->model_type::find($request->model_id);

            $receiver = $this->videoService->receiveVideo($request,$model);

            if ($receiver && $receiver['status']) {
                if(isset($receiver['video']))
                    return $this->getOptions($receiver['video']->id);
                else
                    return response()->json([$receiver]);
            }

            return Response()->json([false, __('apps::dashboard.messages.failed')],500);
        } catch (\PDOException $e) {
            return Response()->json([false, $e->errorInfo[2]],500);
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
            'build_uploader' => !$video,
            'is_ready' => $video?->is_ready,
            'video_source' =>$video?->is_ready ? route(config('hls-videos.access_route_stream'),[$video->id]) : '',
            'video_id' => $video?->id
        ]);
    }

    public function deleteVideo(Request $request,$id)
    {
        try {
            VideoService::deleteVideo($id);
            return $this->getOptions();
        } catch (\PDOException $e) {
            return Response()->json([false, $e->errorInfo[2]],500);
        } 
    }
}