<?php

namespace  HlsVideos\Services\Qualities;

use  HlsVideos\DTOS\VideoConverted;
use  HlsVideos\Models\HlsVideoQuality;
use  HlsVideos\Services\Contracts\VideoQualityProcessorInterface;

class Mp4ToService implements VideoQualityProcessorInterface
{
    protected $quality;
    protected $video;
    protected $headers;

    public function __construct() {
        $this->headers = ['Authorization' => config('hls-videos.mp4_to_token')];
    }

    public function convertVideo($videoFile, HlsVideoQuality $quality): VideoConverted{
        logger("Starting conversion for quality {$quality->quality} of video ID {$quality->video->id}");

        $this->video = $quality->video;
        $this->quality = $quality;

        $client = new \GuzzleHttp\Client();
        logger("Fetching API URL from mp4.to");
        $response = $client->get('https://www.mp4.to/apis/');
        $content = json_decode($response->getBody()->getContents());
        $apiUrl = $content->api;
        logger("API URL retrieved: $apiUrl");

        $multipart = [
            ['name' => 'lang','contents' => 'en'],
            ['name' => 'convert_to','contents' => "{$this->video->original_extension}-hls"],
            [
                'name' => "file[0]",
                'contents' => fopen($videoFile, 'r'),
                'filename' => basename($videoFile),
                'headers'  => [
                    'Content-Type' => mime_content_type($videoFile),
                ]
            ]
        ];

        $response = $client->post( "$apiUrl/v1/convert/", [
            'headers' => $this->headers,
            'multipart' => $multipart,
            'allow_redirects' => true
        ]);

        $responseBody = $response->getBody()->getContents();
        $result = json_decode($responseBody);
        logger("Conversion request response", ['body' => $responseBody]);
        
        logger("Starting to poll for conversion results.");
        $this->getConversionResults($result, $apiUrl, $client);

        return new VideoConverted($this->quality);
    }

    private function downloadFile($url, $filename, $apiUrl, $client)
    {
        logger("Downloading file: $filename from URL segment: $url");
        // Fix URL construction - ensure proper URL formatting
        $fullUrl = rtrim($apiUrl, '/') . '/' . ltrim($url, '/');
        logger("Full download URL: $fullUrl");
            
        $response = $client->get($fullUrl);
        $data = $response->getBody()->getContents();
        
        // Save file in original directory
        $fullPath = "{$this->quality->process_folder_path}/$filename";
        file_put_contents($fullPath, $data);
        logger("File saved to: $fullPath");

        return true;
    }

    private function getConversionResults($params, $apiUrl, $client)
    {
        static $pollCount = 0;
        $pollCount++;

        logger("Polling for results. Attempt #$pollCount");
        if($pollCount >= 20)
        {
            logger()->error("Conversion failed after 20 polling attempts.");
            throw new \Exception("Conversion Error Detected: after 20 try");
        }
        
        if (isset($params->error)) {
            logger()->error("Conversion API returned an error.", ['error' => $params->error]);
            throw new \Exception("Conversion Error Detected: " . $params->error);
        }

        try {
            
            $response = $client->post($apiUrl . '/v1/results/', [
                'headers' => $this->headers,
                'form_params' => (array) $params
            ]);

            $responseBody = $response->getBody()->getContents();
            $content = json_decode($responseBody);
            logger("Polling response", ['body' => $responseBody]);
            
            if ($content->finished == false) {
                logger("Conversion not finished. Waiting 5 seconds.");
                
                if (intval($content->queue_count) > 0) {
                    logger("Items in queue: " . $content->queue_count);
                }

                sleep(5);
                
                $this->getConversionResults($params, $apiUrl, $client);
                return;
            }

            logger("Conversion finished. Processing files.");
            if (isset($content->files) && is_array($content->files)) {
                foreach ($content->files as $index => $file) {
                    
                    $this->downloadFile($file->url, $file->filename, $apiUrl, $client);
                    
                }
                logger("All files downloaded.");
            } else {
                logger()->warning("Conversion finished but no files were found in the response.");
            }

        } catch (\Exception $e) {
            logger()->error("Exception while polling for results.", ['exception' => $e->getMessage()]);
            throw new \Exception("Get Results Failed: " . $e->getMessage());
        }
    }
}
