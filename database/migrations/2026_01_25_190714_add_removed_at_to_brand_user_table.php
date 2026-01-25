<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase MI-1: Add removed_at to brand_user table for soft deletion.
 * 
 * Active membership is defined as removed_at IS NULL.
 * This prevents ghost permissions and enables clean re-addition.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('brand_user', function (Blueprint $table) {
            if (!Schema::hasColumn('brand_user', 'removed_at')) {
                $table->timestamp('removed_at')->nullable()->after('updated_at');
                $table->index('removed_at');
                // Composite index for active membership queries
                $table->index(['brand_id', 'user_id', 'removed_at']);
            }
        });
        
        // Phase MI-1: Note - Duplicate active memberships are prevented at application level
        // MySQL doesn't support partial unique indexes (unique where removed_at IS NULL) directly
        // Application-level validation in User::setRoleForBrand() enforces this constraint
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brand_user', function (Blueprint $table) {
            if (Schema::hasColumn('brand_user', 'removed_at')) {
                $table->dropIndex(['brand_id', 'user_id', 'removed_at']);
                $table->dropIndex(['removed_at']);
                $table->dropColumn('removed_at');
            }
        });
    }
};
