<?php

namespace HlsVideos\Services\Qualities;

use HlsVideos\DTOS\VideoConverted;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Models\HlsVideoQuality;
use HlsVideos\Services\Contracts\VideoQualityProcessorInterface;

class UploadToStepsencoderService implements VideoQualityProcessorInterface
{
    protected $quality;
    protected $video;
    protected $headers;

    public function __construct()
    {
        $this->headers = ['Authorization' => config('hls-videos.steps_encoder_token')];
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
        $response = $client->post("$nodeUrl/v1/hls/videos/upload-from-server/{$this->video->id}", [
            'headers' => $this->headers,
            'allow_redirects' => true,
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => $videoFile,
                ],
                [
                    'name' => 'tenant_id',
                    'contents' => app('currentTenant')->id,
                ]
            ]
        ]);

        $this->video->qualities()->delete();
        logger("UploadToStepsencoderService: ", [$response->getBody()->getContents()]);

        return new VideoConverted($quality, true);
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
