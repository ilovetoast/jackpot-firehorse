<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add database indexes to support AssetSearchService and listing queries.
 * Indexes only; no column changes. Safe for staging and production.
 * Uses conditional checks so indexes are not duplicated.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $this->upMysql();
        } elseif ($driver === 'pgsql') {
            $this->upPgsql();
        }
    }

    private function upMysql(): void
    {
        $schema = Schema::getConnection()->getDatabaseName();

        // assets
        $this->addIndexIfNotExists('assets', 'assets_tenant_brand_type_category_idx', function () {
            Schema::table('assets', function (Blueprint $table) {
                $table->index(['tenant_id', 'brand_id', 'type'], 'assets_tenant_brand_type_category_idx');
            });
        }, $schema);
        $this->addIndexIfNotExists('assets', 'assets_original_filename_idx', function () {
            Schema::table('assets', function (Blueprint $table) {
                $table->index('original_filename', 'assets_original_filename_idx');
            });
        }, $schema);
        $this->addIndexIfNotExists('assets', 'assets_title_idx', function () {
            Schema::table('assets', function (Blueprint $table) {
                $table->index('title', 'assets_title_idx');
            });
        }, $schema);

        // asset_tags: (asset_id), (tag) often exist; add (tag, asset_id) if missing
        $this->addIndexIfNotExists('asset_tags', 'asset_tags_tag_asset_id_idx', function () {
            Schema::table('asset_tags', function (Blueprint $table) {
                $table->index(['tag', 'asset_id'], 'asset_tags_tag_asset_id_idx');
            });
        }, $schema);

        // collections
        $this->addIndexIfNotExists('collections', 'collections_name_idx', function () {
            Schema::table('collections', function (Blueprint $table) {
                $table->index('name', 'collections_name_idx');
            });
        }, $schema);

        // asset_collections: add only if no index on column yet (create migration may already add them)
        if (!$this->mysqlHasIndexOnColumn($schema, 'asset_collections', 'asset_id')) {
            Schema::table('asset_collections', function (Blueprint $table) {
                $table->index('asset_id', 'asset_collections_asset_id_idx');
            });
        }
        if (!$this->mysqlHasIndexOnColumn($schema, 'asset_collections', 'collection_id')) {
            Schema::table('asset_collections', function (Blueprint $table) {
                $table->index('collection_id', 'asset_collections_collection_id_idx');
            });
        }
    }

    private function mysqlHasIndexOnColumn(string $schema, string $table, string $column): bool
    {
        $rows = DB::select(
            "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
            [$schema, $table, $column]
        );
        return !empty($rows);
    }

    private function upPgsql(): void
    {
        // assets
        $this->addIndexIfNotExistsPg('assets', 'assets_tenant_brand_type_category_idx', 'CREATE INDEX assets_tenant_brand_type_category_idx ON assets (tenant_id, brand_id, type)');
        $this->addIndexIfNotExistsPg('assets', 'assets_original_filename_idx', 'CREATE INDEX assets_original_filename_idx ON assets (original_filename)');
        $this->addIndexIfNotExistsPg('assets', 'assets_title_idx', 'CREATE INDEX assets_title_idx ON assets (title)');

        // asset_tags
        $this->addIndexIfNotExistsPg('asset_tags', 'asset_tags_tag_asset_id_idx', 'CREATE INDEX asset_tags_tag_asset_id_idx ON asset_tags (tag, asset_id)');

        // collections
        $this->addIndexIfNotExistsPg('collections', 'collections_name_idx', 'CREATE INDEX collections_name_idx ON collections (name)');

        // asset_collections
        $this->addIndexIfNotExistsPg('asset_collections', 'asset_collections_asset_id_idx', 'CREATE INDEX asset_collections_asset_id_idx ON asset_collections (asset_id)');
        $this->addIndexIfNotExistsPg('asset_collections', 'asset_collections_collection_id_idx', 'CREATE INDEX asset_collections_collection_id_idx ON asset_collections (collection_id)');
    }

    private function addIndexIfNotExists(string $table, string $indexName, callable $add, string $schema): void
    {
        $exists = DB::select(
            "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$schema, $table, $indexName]
        );
        if (empty($exists)) {
            $add();
        }
    }

    private function addIndexIfNotExistsPg(string $table, string $indexName, string $sql): void
    {
        $exists = DB::select("SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $indexName]);
        if (empty($exists)) {
            DB::statement($sql);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->safeDropIndex('assets', 'assets_tenant_brand_type_category_idx');
        $this->safeDropIndex('assets', 'assets_original_filename_idx');
        $this->safeDropIndex('assets', 'assets_title_idx');
        $this->safeDropIndex('asset_tags', 'asset_tags_tag_asset_id_idx');
        $this->safeDropIndex('collections', 'collections_name_idx');
        $this->safeDropIndex('asset_collections', 'asset_collections_asset_id_idx');
        $this->safeDropIndex('asset_collections', 'asset_collections_collection_id_idx');
    }

    private function safeDropIndex(string $table, string $indexName): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            $schema = Schema::getConnection()->getDatabaseName();
            $exists = DB::select("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?", [$schema, $table, $indexName]);
            if (!empty($exists)) {
                Schema::table($table, function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
        } elseif ($driver === 'pgsql') {
            $exists = DB::select("SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $indexName]);
            if (!empty($exists)) {
                DB::statement("DROP INDEX IF EXISTS {$indexName}");
            }
        }
    }
};
