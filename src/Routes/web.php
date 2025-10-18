<?php
use Illuminate\Support\Facades\Route;
use HlsVideos\Http\Controllers\HlsVideoController;
use HlsVideos\Http\Controllers\HlsFolderController;
use HlsVideos\Http\Controllers\HlsFolderVideoController;

$middleware = config('hls-videos.uploader_access_middleware') ? explode(',', config('hls-videos.uploader_access_middleware')) : [];
Route::middleware($middleware)->group(function () {
    Route::name('hls.videos.')
        ->prefix('hls/videos')
        ->group(function () {

            Route::get('list', [HlsVideoController::class, 'list'])->name('list');
            Route::post('upload-from-server/{videoId}', [HlsVideoController::class, 'uploadFromServer'])->name('upload-from-server');
            Route::any('upload', [HlsVideoController::class, 'uploadVideo'])->name('upload');
            Route::post('assign-video-to-module', [HlsVideoController::class, 'assignVideoToModule'])->name('assign-video-to-module');
            Route::get('video-options/{videoId?}', [HlsVideoController::class, 'getOptions'])->name('options');
            Route::delete('video-delete/{videoId}', [HlsVideoController::class, 'deleteVideo'])->name('delete');
        });

    Route::name('hls.folders.')
        ->prefix('hls/folders')
        ->group(function () {

            Route::get('/list', [HlsFolderController::class, 'list'])->name('list');
            Route::get('/search', [HlsFolderController::class, 'search'])->name('search');
            Route::post('/create', [HlsFolderController::class, 'create'])->name('create');
            Route::post('/rename', [HlsFolderController::class, 'rename'])->name('rename');
            Route::delete('/delete', [HlsFolderController::class, 'delete'])->name('delete');
            Route::post('/move', [HlsFolderController::class, 'move'])->name('move');

            Route::name('videos.')->prefix('videos')->group(function () {

                Route::post('/move', [HlsFolderVideoController::class, 'move'])->name('move');
                Route::post('/copy', [HlsFolderVideoController::class, 'copy'])->name('copy');
                Route::post('/rename', [HlsFolderVideoController::class, 'rename'])->name('move');
                Route::delete('/delete', [HlsFolderVideoController::class, 'delete'])->name('delete');
            });
        });
});