<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C3: Add tenant support to metadata_fields table.
 *
 * This migration adds:
 * - tenant_id: For tenant-scoped fields (nullable, only for scope='tenant')
 * - is_active: Soft disable flag for tenant fields
 * - Updates unique constraint: key must be unique per tenant (for tenant fields)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('metadata_fields', function (Blueprint $table) {
            // Add tenant_id (nullable - only for tenant-scoped fields)
            if (!Schema::hasColumn('metadata_fields', 'tenant_id')) {
                $table->foreignId('tenant_id')
                    ->nullable()
                    ->after('scope')
                    ->constrained('tenants')
                    ->onDelete('cascade');
            }

            // Add is_active flag (for soft disabling tenant fields)
            if (!Schema::hasColumn('metadata_fields', 'is_active')) {
                $table->boolean('is_active')
                    ->default(true)
                    ->after('tenant_id');
            }

            // Add index for tenant_id lookups
            if (!$this->hasIndex('metadata_fields', 'metadata_fields_tenant_id_index')) {
                $table->index('tenant_id');
            }

            // Add composite index for tenant + scope lookups
            if (!$this->hasIndex('metadata_fields', 'metadata_fields_tenant_scope_idx')) {
                $table->index(['tenant_id', 'scope'], 'metadata_fields_tenant_scope_idx');
            }
        });

        // Note: Unique constraint handling:
        // - System fields (scope='system', tenant_id IS NULL): key must be globally unique (enforced in application)
        // - Tenant fields (scope='tenant', tenant_id IS NOT NULL): (tenant_id, key) must be unique
        // MySQL unique indexes treat NULLs specially, so we add a unique constraint on (tenant_id, key)
        // which will allow multiple NULL tenant_ids to have the same key. We enforce system field
        // uniqueness in application logic (TenantMetadataFieldService).
        Schema::table('metadata_fields', function (Blueprint $table) {
            // Add unique constraint on (tenant_id, key) for tenant fields
            // This allows system fields (tenant_id IS NULL) to have duplicate keys, which we prevent in app logic
            if (!$this->hasIndex('metadata_fields', 'metadata_fields_tenant_key_unique')) {
                $table->unique(['tenant_id', 'key'], 'metadata_fields_tenant_key_unique');
            }
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
        Schema::table('metadata_fields', function (Blueprint $table) {
            // Drop indexes
            if ($this->hasIndex('metadata_fields', 'metadata_fields_tenant_scope_idx')) {
                $table->dropIndex('metadata_fields_tenant_scope_idx');
            }
            if ($this->hasIndex('metadata_fields', 'metadata_fields_tenant_id_index')) {
                $table->dropIndex('metadata_fields_tenant_id_index');
            }

            // Drop columns
            if (Schema::hasColumn('metadata_fields', 'is_active')) {
                $table->dropColumn('is_active');
            }
            if (Schema::hasColumn('metadata_fields', 'tenant_id')) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            }

            // Restore original unique constraint on key
            if (!$this->hasIndex('metadata_fields', 'metadata_fields_key_unique')) {
                $table->unique('key', 'metadata_fields_key_unique');
            }
        });
    }
};
