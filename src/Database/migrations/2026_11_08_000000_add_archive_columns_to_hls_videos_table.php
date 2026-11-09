<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a video's files live and the state of its segments, for the video
     * archive (base_backend docs/video-archive-plan.md). `status` is untouched:
     * a video stays `ready` while it is archived.
     *
     *   storage_bucket    legacy = the shared bucket, tenant = the tenant's own bucket
     *   storage_state     hot, migrating, archiving, archived, restoring, failed
     *   archive_*         where the archive is on the Storage Box, and its manifest
     *                     without the per-file lists (the full one is on the box)
     *   hot_size_bytes    size of the files the archive holds
     *   last_played_at    last time a player loaded the master playlist (at most hourly)
     */
    public function up()
    {
        Schema::table('hls_videos', function (Blueprint $table) {
            if (! Schema::hasColumn('hls_videos', 'storage_bucket')) {
                $table->string('storage_bucket', 16)->default('legacy');
            }
            if (! Schema::hasColumn('hls_videos', 'storage_state')) {
                $table->string('storage_state', 16)->default('hot');
            }
            if (! Schema::hasColumn('hls_videos', 'archive_box')) {
                $table->string('archive_box', 64)->nullable();
            }
            if (! Schema::hasColumn('hls_videos', 'archive_path')) {
                $table->string('archive_path')->nullable();
            }
            if (! Schema::hasColumn('hls_videos', 'archive_manifest')) {
                $table->json('archive_manifest')->nullable();
            }
            if (! Schema::hasColumn('hls_videos', 'hot_size_bytes')) {
                $table->unsignedBigInteger('hot_size_bytes')->nullable();
            }
            foreach (['archived_at', 'restored_at', 'last_active_at', 'last_played_at'] as $column) {
                if (! Schema::hasColumn('hls_videos', $column)) {
                    $table->timestamp($column)->nullable();
                }
            }
            if (! Schema::hasColumn('hls_videos', 'storage_error')) {
                $table->text('storage_error')->nullable();
            }
        });

        try {
            Schema::table('hls_videos', function (Blueprint $table) {
                $table->index(['storage_state', 'storage_bucket'], 'hls_videos_storage_index');
            });
        } catch (\Throwable $e) {
            // Index already present.
        }
    }

    public function down()
    {
        try {
            Schema::table('hls_videos', function (Blueprint $table) {
                $table->dropIndex('hls_videos_storage_index');
            });
        } catch (\Throwable $e) {
            //
        }

        $columns = array_values(array_filter([
            'storage_bucket', 'storage_state', 'archive_box', 'archive_path', 'archive_manifest', 'hot_size_bytes',
            'archived_at', 'restored_at', 'last_active_at', 'last_played_at', 'storage_error',
        ], fn ($column) => Schema::hasColumn('hls_videos', $column)));

        if ($columns) {
            Schema::table('hls_videos', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
