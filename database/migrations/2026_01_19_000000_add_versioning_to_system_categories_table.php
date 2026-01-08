<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('system_categories', function (Blueprint $table) {
            $table->integer('version')->default(1)->after('id');
            $table->string('change_summary')->nullable()->after('version');
            
            // Add index for efficient lookups
            $table->index(['slug', 'asset_type', 'version'], 'system_category_version_lookup');
        });

        // Set all existing rows to version 1
        DB::table('system_categories')->update(['version' => 1]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_categories', function (Blueprint $table) {
            $table->dropIndex('system_category_version_lookup');
            $table->dropColumn(['version', 'change_summary']);
        });
    }
};
