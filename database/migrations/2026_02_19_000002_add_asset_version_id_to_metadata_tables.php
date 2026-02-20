<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3B: Attach metadata to asset_version.
 *
 * Additive only. asset_id remains for backward compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_metadata', function (Blueprint $table) {
            $table->uuid('asset_version_id')->nullable()->after('asset_id')->index();
        });

        Schema::table('asset_metadata_candidates', function (Blueprint $table) {
            $table->uuid('asset_version_id')->nullable()->after('asset_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('asset_metadata', function (Blueprint $table) {
            $table->dropColumn('asset_version_id');
        });

        Schema::table('asset_metadata_candidates', function (Blueprint $table) {
            $table->dropColumn('asset_version_id');
        });
    }
};
