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
        Schema::table('brands', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('slug');
            $table->boolean('is_default')->default(false)->after('logo_path');
        });
        
        // Note: MySQL doesn't support filtered unique indexes (WHERE clause)
        // We'll enforce uniqueness at the application level in the Brand model
        // For PostgreSQL/SQLite, we could use: CREATE UNIQUE INDEX tenant_default_brand ON brands (tenant_id) WHERE is_default = 1;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'is_default']);
        });
    }
};
