<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make upload_session_id and storage_bucket_id nullable on assets table.
 *
 * Programmatically-created assets (website crawled logos, AI-generated assets)
 * don't go through the upload flow and therefore have no upload session or
 * explicit storage bucket assignment. These columns must be nullable to
 * support that workflow while preserving FK integrity for uploaded assets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['upload_session_id']);
            $table->dropUnique(['upload_session_id']);
            $table->dropForeign(['storage_bucket_id']);
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->uuid('upload_session_id')->nullable()->change();
            $table->uuid('storage_bucket_id')->nullable()->change();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('upload_session_id')
                ->references('id')
                ->on('upload_sessions')
                ->onDelete('cascade');

            $table->unique('upload_session_id');

            $table->foreign('storage_bucket_id')
                ->references('id')
                ->on('storage_buckets')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['upload_session_id']);
            $table->dropUnique(['upload_session_id']);
            $table->dropForeign(['storage_bucket_id']);
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->uuid('upload_session_id')->nullable(false)->change();
            $table->uuid('storage_bucket_id')->nullable(false)->change();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreign('upload_session_id')
                ->references('id')
                ->on('upload_sessions')
                ->onDelete('cascade');

            $table->unique('upload_session_id');

            $table->foreign('storage_bucket_id')
                ->references('id')
                ->on('storage_buckets')
                ->onDelete('cascade');
        });
    }
};
