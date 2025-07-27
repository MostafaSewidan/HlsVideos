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
        Schema::create('hls_video_qualities', function (Blueprint $table) {
            $table->id();
            $table->uuid('hls_video_id');
            $table->string('quality');
            $table->string('convert_service');
            $table->string('save_disk')->nullable();
            $table->enum('status',['pending','converting','uploading','ready'])->default('pending');
            $table->foreign('hls_video_id')->references('id')->on('hls_videos')->onDelete('cascade');
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
        Schema::dropIfExists('hls_video_qualities');
    }
};
