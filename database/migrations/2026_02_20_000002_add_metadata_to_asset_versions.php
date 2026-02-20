<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add metadata JSON column to asset_versions.
 * Stores version-scoped thumbnail paths, dimensions, etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_versions', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('storage_class');
        });
    }

    public function down(): void
    {
        Schema::table('asset_versions', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
