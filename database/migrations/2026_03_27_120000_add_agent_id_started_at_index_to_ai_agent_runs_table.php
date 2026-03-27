<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Covers: WHERE agent_id = ? ORDER BY started_at DESC LIMIT 1
 * Without this, MySQL may filesort all rows for that agent (sort buffer / 1038 errors on large tables).
 */
return new class extends Migration
{
    private const INDEX_NAME = 'ai_agent_runs_agent_id_started_at_index';

    public function up(): void
    {
        if (! Schema::hasTable('ai_agent_runs')) {
            return;
        }

        if ($this->indexExists('ai_agent_runs', self::INDEX_NAME)) {
            return;
        }

        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->index(
                ['agent_id', 'started_at'],
                self::INDEX_NAME
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_agent_runs')) {
            return;
        }

        if (! $this->indexExists('ai_agent_runs', self::INDEX_NAME)) {
            return;
        }

        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->dropIndex(self::INDEX_NAME);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
