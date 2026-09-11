<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the columns the direct-to-R2 upload flow needs, and widens the
     * `status` column from ENUM to VARCHAR.
     *
     * Why VARCHAR instead of adding values to the ENUM: altering an ENUM on
     * MySQL rebuilds and locks the whole table, and this migration has to run
     * against every tenant database. Doing it once now means no future status
     * ever needs a migration again.
     *
     * The default stays 'uploaded' so the legacy server-upload path behaves
     * exactly as before.
     */
    public function up()
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // Raw statement on purpose: ->change() needs doctrine/dbal, which
            // cannot introspect ENUM columns without extra type registration.
            DB::statement("ALTER TABLE `hls_videos` MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'uploaded'");
        }

        Schema::table('hls_videos', function (Blueprint $table) {

            if (! Schema::hasColumn('hls_videos', 'r2_key')) {
                $table->string('r2_key', 1024)->nullable()->after('original_file_name');
            }

            if (! Schema::hasColumn('hls_videos', 'r2_upload_id')) {
                $table->string('r2_upload_id', 255)->nullable()->after('r2_key');
            }

            if (! Schema::hasColumn('hls_videos', 'upload_size')) {
                $table->unsignedBigInteger('upload_size')->nullable()->after('r2_upload_id');
            }

            if (! Schema::hasColumn('hls_videos', 'upload_started_at')) {
                $table->timestamp('upload_started_at')->nullable()->after('upload_size');
            }
        });

        // Index used by the reconcile/cleanup command, which scans by status.
        try {
            Schema::table('hls_videos', function (Blueprint $table) {
                $table->index(['status', 'upload_started_at'], 'hls_videos_upload_state_index');
            });
        } catch (\Throwable $e) {
            // Index already present.
        }
    }

    public function down()
    {
        try {
            Schema::table('hls_videos', function (Blueprint $table) {
                $table->dropIndex('hls_videos_upload_state_index');
            });
        } catch (\Throwable $e) {
            //
        }

        Schema::table('hls_videos', function (Blueprint $table) {
            foreach (['upload_started_at', 'upload_size', 'r2_upload_id', 'r2_key'] as $column) {
                if (Schema::hasColumn('hls_videos', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // `status` is intentionally left as VARCHAR: narrowing it back to an
        // ENUM would destroy rows holding the newer statuses.
    }
};
