<?php
use Illuminate\Support\Facades\Route;
use HlsVideos\Http\Controllers\HlsVideoController;

Route::name('hls.videos.')
    ->prefix('hls/videos')
    ->middleware(config('hls-videos.uploader_access_middleware'))
    ->group(function () {

    Route::get('list', [HlsVideoController::class, 'list'])->name('list');
    Route::post('upload-from-server/{videoId}', [HlsVideoController::class, 'uploadFromServer'])->name('upload-from-server');
    Route::any('upload', [HlsVideoController::class, 'uploadVideo'])->name('upload');
    Route::get('video-options/{videoId?}', [HlsVideoController::class, 'getOptions'])->name('options');
    Route::delete('video-delete/{videoId}', [HlsVideoController::class, 'deleteVideo'])->name('delete');
});