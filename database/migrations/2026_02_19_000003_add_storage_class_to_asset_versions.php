<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.5: Glacier awareness.
 * Add storage_class to asset_versions (STANDARD, GLACIER, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_versions', function (Blueprint $table) {
            $table->string('storage_class', 32)->nullable()->after('checksum');
        });
    }

    public function down(): void
    {
        Schema::table('asset_versions', function (Blueprint $table) {
            $table->dropColumn('storage_class');
        });
    }
};
