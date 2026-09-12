<?php

namespace HlsVideos\Services\Qualities;

use HlsVideos\DTOS\VideoConverted;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Models\HlsVideoQuality;
use HlsVideos\Services\Contracts\VideoQualityProcessorInterface;
use HlsVideos\Services\VideoService;

class UploadToStepsLocalencoderService implements VideoQualityProcessorInterface
{
    protected $quality;
    protected $video;
    protected $headers;

    public function __construct()
    {
        $this->headers = [
            'X-Steps-Password' => config('hls-videos.local_server_password'),
            'Accept' => 'application/json',
        ];
    }


    public function convertVideo($videoFile, HlsVideoQuality $quality): VideoConverted
    {
        $nodeUrl = $this->getTheBestNode();
        $this->video = $quality->video;
        $stream_data = $this->video->stream_data;
        $stream_data['incode_url'] = $nodeUrl;
        $this->video->update([
            'stream_data' => $stream_data
        ]);

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
