<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MP4 copies of a video's HLS quality, made on demand by remuxing the
     * segments (no re-encode) so the dashboard can download the video.
     *
     * One row per (video, quality, disk). A row is a cache entry: it is reused
     * while it has not expired, and the prune command removes the file after.
     * `status` is VARCHAR for the same reason as hls_videos.status: adding a
     * value never needs a migration against every tenant database.
     */
    public function up()
    {
        Schema::create('hls_video_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('hls_video_id');
            $table->string('quality', 20);
            $table->string('disk', 50);
            $table->string('path', 1024)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['hls_video_id', 'quality', 'disk']);
            $table->index(['status', 'expires_at']);
            $table->foreign('hls_video_id')->references('id')->on('hls_videos')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('hls_video_exports');
    }
};
