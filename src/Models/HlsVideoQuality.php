<?php

namespace  HlsVideos\Models;

use Illuminate\Database\Eloquent\Model;
use  HlsVideos\Jobs\ConvertQualityJob;
use Illuminate\Support\Facades\Storage;

class HlsVideoQuality extends Model
{
    const CONVERTING = 'converting';
    const UPLOADING = 'uploading';
    const READY = 'ready';

    protected $guarded = ['id'];
    protected $casts = [
        'convert_data' => 'array'
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function ($videoQuality) {
            
            // Create directories
            if (!is_dir($videoQuality->process_folder_path)) {
                mkdir($videoQuality->process_folder_path, 0755, true);
            }

            ConvertQualityJob::dispatch($videoQuality)->onQueue('default');;
        });
    }

    public function video(){

        return $this->belongsTo(HlsVideo::class,'hls_video_id');
    }

    public function getProcessFolderPathAttribute(){

        return Storage::disk(config('hls-videos.temp_disk'))->path("{$this->hls_video_id}/{$this->quality}");
    }

    public function scopeNotReady($q){

        return $q->where('status', '!=', self::READY);
    }

    public function updateStatusTo($status){

        return $this->update(['status' => $status]);
    }
}
