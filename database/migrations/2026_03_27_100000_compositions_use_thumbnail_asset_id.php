<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composition previews: store thumbnails as Asset rows on canonical tenant storage (S3 / same as DAM),
 * not on the local public disk.
 *
 * Runs after 2026_03_26_100000_add_thumbnail_path_to_composition_versions_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compositions', function (Blueprint $table) {
            $table->uuid('thumbnail_asset_id')->nullable()->after('document_json');
            $table->foreign('thumbnail_asset_id')->references('id')->on('assets')->nullOnDelete();
            $table->dropColumn('thumbnail_path');
        });

        Schema::table('composition_versions', function (Blueprint $table) {
            $table->uuid('thumbnail_asset_id')->nullable()->after('label');
            $table->foreign('thumbnail_asset_id')->references('id')->on('assets')->nullOnDelete();
            $table->dropColumn('thumbnail_path');
        });
    }

    public function down(): void
    {
        Schema::table('composition_versions', function (Blueprint $table) {
            $table->dropForeign(['thumbnail_asset_id']);
            $table->dropColumn('thumbnail_asset_id');
            $table->string('thumbnail_path', 512)->nullable();
        });

        Schema::table('compositions', function (Blueprint $table) {
            $table->dropForeign(['thumbnail_asset_id']);
            $table->dropColumn('thumbnail_asset_id');
            $table->string('thumbnail_path', 512)->nullable();
        });
    }
};
