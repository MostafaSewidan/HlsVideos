<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use HlsVideos\Models\HlsFolder;

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
        
        // Not the HlsVideo model: it soft deletes, and deleted_at comes in a later migration.
        foreach (\Illuminate\Support\Facades\DB::table('hls_videos')->pluck('id') as $videoId) {
            $masterFolder->videos()->syncWithoutDetaching([$videoId]);
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
