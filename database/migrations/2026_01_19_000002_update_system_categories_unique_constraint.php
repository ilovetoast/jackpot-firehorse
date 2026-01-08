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
        Schema::table('system_categories', function (Blueprint $table) {
            // Drop the old unique constraint on (slug, asset_type)
            $table->dropUnique('system_category_unique_slug');
            
            // Add new unique constraint on (slug, asset_type, version)
            // This allows multiple versions of the same template while ensuring each version is unique
            $table->unique(['slug', 'asset_type', 'version'], 'system_category_unique_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_categories', function (Blueprint $table) {
            // Drop the version-based unique constraint
            $table->dropUnique('system_category_unique_version');
            
            // Restore the original unique constraint on (slug, asset_type)
            // Note: This may fail if there are multiple versions, so this migration may not be fully reversible
            $table->unique(['slug', 'asset_type'], 'system_category_unique_slug');
        });
    }
};
