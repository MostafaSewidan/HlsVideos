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
        Schema::table('hls_video_qualities', function (Blueprint $table) {
            $table->json('convert_data')->nullable()->after('quality');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('hls_video_qualities', function (Blueprint $table) {
            $table->dropColumn(['convert_data']);
        });
    }
};
