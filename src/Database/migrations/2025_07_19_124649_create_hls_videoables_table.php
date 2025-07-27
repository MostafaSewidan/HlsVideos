<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('hls_videoables', function (Blueprint $table) {
            $table->id();
            $table->uuid('hls_video_id');
            $table->foreign('hls_video_id')->references('id')->on('hls_videos')->onDelete('cascade');
            $table->morphs('videoable');
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
        Schema::dropIfExists('hls_videoables');
    }
};
