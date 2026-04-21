<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The original metadata_fields table used a global UNIQUE on `key`.
 * Tenant-scoped fields added a composite UNIQUE (tenant_id, key) but the global
 * index was never dropped, so MySQL still rejected duplicate keys across tenants
 * (e.g. two tenants both using custom__product), while PHP only checked the
 * current tenant — causing raw SQLSTATE[23000] errors on insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasIndex('metadata_fields', 'metadata_fields_key_unique')) {
            Schema::table('metadata_fields', function (Blueprint $table) {
                $table->dropUnique('metadata_fields_key_unique');
            });
        }
    }

    public function down(): void
    {
        if (! $this->hasIndex('metadata_fields', 'metadata_fields_key_unique')) {
            Schema::table('metadata_fields', function (Blueprint $table) {
                $table->unique('key', 'metadata_fields_key_unique');
            });
        }
    }

    protected function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $databaseName = $connection->getDatabaseName();

        $result = DB::select(
            'SELECT COUNT(*) as count FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$databaseName, $table, $indexName]
        );

        return (int) $result[0]->count > 0;
    }
};
