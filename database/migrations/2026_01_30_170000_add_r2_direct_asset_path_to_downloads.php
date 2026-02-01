<?php

/**
 * UX-R2: Single-asset downloads. Add direct_asset_path for downloads that
 * serve a single file (no ZIP). Null for ZIP-based downloads.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->string('direct_asset_path')->nullable()->after('zip_path');
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn('direct_asset_path');
        });
    }
};
