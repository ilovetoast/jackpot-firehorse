<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add indexes for Admin Asset Operations Console.
 * Supports cross-tenant filtering and pagination at scale (100k+ assets).
 */
return new class extends Migration
{
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

        $indexes = [
            ['assets', 'assets_created_at_idx', function () {
                Schema::table('assets', function (Blueprint $table) {
                    $table->index('created_at', 'assets_created_at_idx');
                });
            }],
            ['assets', 'assets_deleted_at_idx', function () {
                Schema::table('assets', function (Blueprint $table) {
                    $table->index('deleted_at', 'assets_deleted_at_idx');
                });
            }],
            ['assets', 'assets_storage_root_path_idx', function () {
                Schema::table('assets', function (Blueprint $table) {
                    $table->index('storage_root_path', 'assets_storage_root_path_idx');
                });
            }],
        ];

        foreach ($indexes as [$table, $indexName, $add]) {
            $exists = DB::select(
                "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?",
                [$schema, $table, $indexName]
            );
            if (empty($exists)) {
                $add();
            }
        }

        // MySQL 8: JSON index for metadata->dominant_colors (optional, for color filtering)
        if (Schema::hasColumn('assets', 'metadata')) {
            $jsonIdx = 'assets_metadata_dominant_colors_idx';
            $exists = DB::select(
                "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'assets' AND INDEX_NAME = ?",
                [$schema, $jsonIdx]
            );
            if (empty($exists)) {
                try {
                    DB::statement("CREATE INDEX {$jsonIdx} ON assets ((CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.dominant_colors')) AS CHAR(255))))");
                } catch (\Throwable $e) {
                    // MySQL < 8 or unsupported; skip
                }
            }
        }
    }

    private function upPgsql(): void
    {
        $indexes = [
            ['assets', 'assets_created_at_idx', 'CREATE INDEX assets_created_at_idx ON assets (created_at)'],
            ['assets', 'assets_deleted_at_idx', 'CREATE INDEX assets_deleted_at_idx ON assets (deleted_at)'],
            ['assets', 'assets_storage_root_path_idx', 'CREATE INDEX assets_storage_root_path_idx ON assets (storage_root_path)'],
        ];

        foreach ($indexes as [$table, $indexName, $sql]) {
            $exists = DB::select("SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $indexName]);
            if (empty($exists)) {
                try {
                    DB::statement($sql);
                } catch (\Throwable $e) {
                    // Ignore if already exists
                }
            }
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $schema = Schema::getConnection()->getDatabaseName();

        $toDrop = [
            ['assets', 'assets_created_at_idx'],
            ['assets', 'assets_deleted_at_idx'],
            ['assets', 'assets_storage_root_path_idx'],
            ['assets', 'assets_metadata_dominant_colors_idx'],
        ];

        foreach ($toDrop as [$table, $indexName]) {
            if ($driver === 'mysql') {
                $exists = DB::select("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?", [$schema, $table, $indexName]);
                if (!empty($exists)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($indexName));
                }
            } elseif ($driver === 'pgsql') {
                DB::statement("DROP INDEX IF EXISTS {$indexName}");
            }
        }
    }
};
