<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use  HlsVideos\Models\HlsVideo;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('hls_videos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('status',[HlsVideo::UPLOADED,HlsVideo::PROCESSING,HlsVideo::READY])->default(HlsVideo::UPLOADED);
            $table->string('original_extension');
            $table->string('file_name');
            $table->string('original_file_name');
            $table->string('original_steam_quality')->nullable();
            $table->json('stream_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('hls_videos');
    }
};
