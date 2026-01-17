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
        Schema::table('metadata_field_permissions', function (Blueprint $table) {
            // Check if columns already exist before adding
            if (!Schema::hasColumn('metadata_field_permissions', 'metadata_field_id')) {
                $table->foreignId('metadata_field_id')->constrained('metadata_fields')->onDelete('restrict');
            }
            if (!Schema::hasColumn('metadata_field_permissions', 'role')) {
                $table->string('role'); // User role (owner, admin, manager, editor, viewer, member)
            }
            if (!Schema::hasColumn('metadata_field_permissions', 'tenant_id')) {
                $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            }
            if (!Schema::hasColumn('metadata_field_permissions', 'brand_id')) {
                $table->foreignId('brand_id')->nullable()->constrained('brands')->onDelete('cascade');
            }
            if (!Schema::hasColumn('metadata_field_permissions', 'category_id')) {
                $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('cascade');
            }
            if (!Schema::hasColumn('metadata_field_permissions', 'can_edit')) {
                $table->boolean('can_edit')->default(false);
            }
        });

        // Add indexes if they don't exist (using raw SQL to check)
        $connection = Schema::getConnection();
        $dbName = $connection->getDatabaseName();
        $tableName = 'metadata_field_permissions';
        
        // Check and add index for field_role_tenant
        $indexExists = $connection->selectOne(
            "SELECT COUNT(*) as count FROM information_schema.statistics 
             WHERE table_schema = ? AND table_name = ? AND index_name = ?",
            [$dbName, $tableName, 'metadata_field_permissions_field_role_tenant_idx']
        );
        if (!$indexExists || $indexExists->count == 0) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->index(['metadata_field_id', 'role', 'tenant_id'], 'metadata_field_permissions_field_role_tenant_idx');
            });
        }
        
        // Check and add index for scope
        $indexExists2 = $connection->selectOne(
            "SELECT COUNT(*) as count FROM information_schema.statistics 
             WHERE table_schema = ? AND table_name = ? AND index_name = ?",
            [$dbName, $tableName, 'metadata_field_permissions_scope_idx']
        );
        if (!$indexExists2 || $indexExists2->count == 0) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->index(['tenant_id', 'brand_id', 'category_id'], 'metadata_field_permissions_scope_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('metadata_field_permissions', function (Blueprint $table) {
            // Drop indexes first (using short names)
            $table->dropIndex('mfp_field_role_tenant_idx');
            $table->dropIndex('mfp_scope_idx');
            
            // Drop foreign keys and columns
            $table->dropForeign(['metadata_field_id']);
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['brand_id']);
            $table->dropForeign(['category_id']);
            
            $table->dropColumn([
                'metadata_field_id',
                'role',
                'tenant_id',
                'brand_id',
                'category_id',
                'can_edit',
            ]);
        });
    }
};
