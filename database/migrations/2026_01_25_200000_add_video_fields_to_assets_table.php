<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->integer('video_duration')->nullable()->after('size_bytes')->comment('Video duration in seconds');
            $table->integer('video_width')->nullable()->after('video_duration')->comment('Video width in pixels');
            $table->integer('video_height')->nullable()->after('video_width')->comment('Video height in pixels');
            $table->string('video_poster_url')->nullable()->after('video_height')->comment('URL to video poster thumbnail');
            $table->string('video_preview_url')->nullable()->after('video_poster_url')->comment('URL to hover preview video (short muted MP4)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'video_duration',
                'video_width',
                'video_height',
                'video_poster_url',
                'video_preview_url',
            ]);
        });
    }
};
