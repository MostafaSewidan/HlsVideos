<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A video that lost its last lesson is soft deleted, and its files and row are
     * removed after a few days (hls-videos:purge-trashed).
     */
    public function up()
    {
        if (! Schema::hasColumn('hls_videos', 'deleted_at')) {
            Schema::table('hls_videos', function (Blueprint $table) {
                $table->softDeletes();
                $table->index('deleted_at', 'hls_videos_deleted_at_index');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('hls_videos', 'deleted_at')) {
            Schema::table('hls_videos', function (Blueprint $table) {
                $table->dropIndex('hls_videos_deleted_at_index');
                $table->dropSoftDeletes();
            });
        }
    }
};
