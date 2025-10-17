<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use HlsVideos\Models\HlsFolder;
use HlsVideos\Models\HlsVideo;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('hls_folder_video', function (Blueprint $table) {
            $table->id();
            $table->uuid('hls_video_id');
            $table->unsignedBigInteger('folder_id');
            $table->string('title')->nullable();

            $table->foreign('hls_video_id')
                ->references('id')
                ->on('hls_videos')
                ->onDelete('cascade');

            $table->foreign('folder_id')
                ->references('id')
                ->on('hls_folders')
                ->onDelete('cascade');

            $table->timestamps();
        });

        $masterFolder = HlsFolder::firstOrCreate(
            [
                'title' => 'Home',
                'parent_id' => null,
            ]
        );
        
        foreach (HlsVideo::all() as $video) {
            $masterFolder->videos()->syncWithoutDetaching([$video->id]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('hls_folder_video');
    }
};
