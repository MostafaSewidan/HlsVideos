<?php

namespace HlsVideos\Services\Qualities;

use HlsVideos\DTOS\VideoConverted;
use HlsVideos\Models\HlsVideo;
use HlsVideos\Models\HlsVideoQuality;
use HlsVideos\Services\Contracts\VideoQualityProcessorInterface;
use HlsVideos\Services\VideoService;

class UploadToStepsencoderService implements VideoQualityProcessorInterface
{
    protected $quality;
    protected $video;
    protected $headers;

    public function __construct()
    {
        $this->headers = [
            'Authorization' => config('hls-videos.steps_encoder_token'),
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

        $path = VideoService::getMediaPath()."{$this->video->id}/{$this->video->file_name}";
        $disk = \Storage::disk(config('hls-videos.temp_disk'));

        if (! $disk->exists($path)) {
            throw new \Exception("Video file not found at path: {$path}");
        }

        // Get the full path and open as a resource stream
        $fullPath = $disk->path($path);
        $videoFileResource = fopen($fullPath, 'r');

        $client = new \GuzzleHttp\Client();

        try {
            logger("tenant",
                [
                    'name' => 'tenant_id',
                    'contents' => app('currentTenant')->id,
                ]);
            $response = $client->post("$nodeUrl/hls/videos/upload-from-server/{$this->video->id}", [
                'headers' => $this->headers,
                'allow_redirects' => true,
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => $videoFileResource,
                        'filename' => $this->video->file_name,
                    ],
                    [
                        'name' => 'tenant_id',
                        'contents' => app('currentTenant')->id,
                    ]
                ]
            ]);

            \Storage::disk(config('hls-videos.temp_disk'))->deleteDirectory(VideoService::getMediaPath().$this->video->id);

            return new VideoConverted($quality, true);
        } catch (\Exception $e) {
            throw new \Exception("Upload to stepsencoder failed: ".$e->getMessage());
        } finally {
            // Always close the file resource
            if (is_resource($videoFileResource)) {
                fclose($videoFileResource);
            }
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
