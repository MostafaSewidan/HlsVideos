<?php

namespace HlsVideos\Services\Qualities;

use HlsVideos\DTOS\VideoConverted;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Models\HlsVideoQuality;
use HlsVideos\Services\Contracts\VideoQualityProcessorInterface;
use HlsVideos\Services\VideoService;

class UploadToStepsLocalencoderServic implements VideoQualityProcessorInterface
{
    protected $quality;
    protected $video;
    protected $headers;

    public function __construct()
    {
        $this->headers = [
            'X-Processing-Password' => config('hls-videos.local_server_password'),
            'Accept' => 'application/json',
        ];
    }


    public function convertVideo($videoFile, HlsVideoQuality $quality): VideoConverted
    {
        $nodeUrl = config('hls-videos.local_server_url');
        $this->video = $quality->video;
        $stream_data = $this->video->stream_data;
        $stream_data['incode_url'] = $nodeUrl;
        $this->video->update([
            'stream_data' => $stream_data
        ]);

        $sourcePath = VideoService::getMediaPath()."{$this->video->id}/{$this->video->file_name}";
        $destinationPath = "temp-videos/".VideoService::getMediaPath()."{$this->video->id}/{$this->video->file_name}";
        $localDisk = \Storage::disk(config('hls-videos.temp_disk'));

        if (! $localDisk->exists($sourcePath)) {
            throw new \Exception("Video file not found at path: {$sourcePath}");
        }

        $s3Disk = \Storage::disk(config('hls-videos.uploaded_videos_disk'));  // R2 / S3

        $stream = $localDisk->readStream($sourcePath);

        $s3Disk->put($destinationPath, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        // Optional: delete local file after upload
        $localDisk->deleteDirectory(VideoService::getMediaPath()."{$this->video->id}");

        $client = new \GuzzleHttp\Client();

        try {
            $data = [
                "tenant_id" => app('currentTenant')->id,
                "type" => "video",
                "data" => $this->video->toArray()
            ];

            $response = $client->post("$nodeUrl/processing-transactions/create", [
                'headers' => $this->headers,
                'allow_redirects' => true,
                'json' => $data
            ]);

            return new VideoConverted($quality, true);
        } finally {
            $stream_data['support_original'] = true;
            $this->video->update([
                'stream_data' => $stream_data
            ]);
        }
    }


    protected function getTheBestNode()
    {
        $activeCounts = HlsVideo::query()
            ->where('status', '!=', HlsVideo::READY)
            ->whereNotNull('stream_data->incode_url')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(stream_data, '$.incode_url')) as node_url, COUNT(*) as jobs")
            ->groupBy('node_url')
            ->pluck('jobs', 'node_url')
            ->toArray();

        $counts = array_fill_keys(config('hls-videos.steps_encoder_urls'), 0);

        foreach ($activeCounts as $nodeUrl => $jobs) {
            if (array_key_exists($nodeUrl, $counts)) {
                $counts[$nodeUrl] = (int) $jobs;
            }
        }

        asort($counts); // keeps keys
        return array_key_first($counts);
    }
}
