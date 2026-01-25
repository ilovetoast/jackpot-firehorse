<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase AF-1: Add requires_approval capability flag to brand_user table.
 * 
 * This is NOT a role - it's a capability flag that controls whether
 * assets uploaded by this user require approval before being visible.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('brand_user', function (Blueprint $table) {
            if (!Schema::hasColumn('brand_user', 'requires_approval')) {
                $table->boolean('requires_approval')->default(false)->after('role');
                $table->index('requires_approval');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brand_user', function (Blueprint $table) {
            if (Schema::hasColumn('brand_user', 'requires_approval')) {
                $table->dropIndex(['requires_approval']);
                $table->dropColumn('requires_approval');
            }
        });
    }
};
