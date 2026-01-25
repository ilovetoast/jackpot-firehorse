<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Phase L.5: Add requires_approval field to categories table.
     * When true, assets in this category require manual approval before publication.
     * When false, assets are auto-published upon upload completion.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(false)->after('is_hidden');
            $table->index('requires_approval'); // Phase L.5.1: Indexed for query performance
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Drop index if it exists (safe for rollback)
            if (Schema::hasColumn('categories', 'requires_approval')) {
                try {
                    $table->dropIndex(['requires_approval']);
                } catch (\Exception $e) {
                    // Index might not exist, ignore
                }
            }
            $table->dropColumn('requires_approval');
        });
    }
};
