<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Phase C1, Step 2: System-level category suppression for metadata fields.
 *
 * This table allows system admins to suppress system metadata fields
 * for specific system category templates.
 *
 * Rules:
 * - System-scoped only (no tenant_id/brand_id)
 * - References system_category (template), not brand-specific categories
 * - Absence of row = visible by default
 * - is_visible = false = suppressed
 * - No ownership transfer to categories
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('metadata_field_category_visibility')) {
            // Table exists but may be missing indexes - add them if needed
            if (!Schema::hasColumn('metadata_field_category_visibility', 'is_visible')) {
                Schema::table('metadata_field_category_visibility', function (Blueprint $table) {
                    $table->boolean('is_visible')->default(false)->after('system_category_id');
                });
            }
            // Add missing indexes
            Schema::table('metadata_field_category_visibility', function (Blueprint $table) {
                if (!$this->hasIndex('metadata_field_category_visibility', 'mf_cat_vis_field_visible_idx')) {
                    $table->index(['metadata_field_id', 'is_visible'], 'mf_cat_vis_field_visible_idx');
                }
            });
            return;
        }

        Schema::create('metadata_field_category_visibility', function (Blueprint $table) {
            $table->id();
            $table->foreignId('metadata_field_id')
                ->constrained('metadata_fields')
                ->onDelete('cascade');
            $table->foreignId('system_category_id')
                ->constrained('system_categories')
                ->onDelete('cascade');
            
            // Visibility flag: false = suppressed, true = visible
            // Default to false (suppressed) for explicit suppression rules
            $table->boolean('is_visible')->default(false);
            
            $table->timestamps();

            // Unique constraint: one rule per field per system category
            $table->unique(['metadata_field_id', 'system_category_id'], 'mf_cat_visibility_unique');
            
            // Indexes for efficient lookups
            $table->index('metadata_field_id');
            $table->index('system_category_id');
            $table->index(['metadata_field_id', 'is_visible'], 'mf_cat_vis_field_visible_idx');
        });
    }

    /**
     * Check if an index exists on a table.
     */
    protected function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $databaseName = $connection->getDatabaseName();
        
        $result = DB::select(
            "SELECT COUNT(*) as count FROM information_schema.statistics 
             WHERE table_schema = ? AND table_name = ? AND index_name = ?",
            [$databaseName, $table, $indexName]
        );
        
        return $result[0]->count > 0;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metadata_field_category_visibility');
    }
};
