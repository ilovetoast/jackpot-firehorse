<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refinements for EBI tables (indexes, column types, engine_version).
     * Safe when re-run: skips indexes/columns that already match.
     */
    public function up(): void
    {
        if (! Schema::hasTable('executions')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $this->refineExecutions();
            $this->refineExecutionAssets();
            $this->refineBrandIntelligenceScores();
        }
    }

    public function down(): void
    {
        // Non-destructive rollback: refinements are additive / type widened.
    }

    protected function indexExists(string $table, string $indexName): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $result = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName]
        );

        return (int) ($result->c ?? 0) > 0;
    }

    protected function refineExecutions(): void
    {
        $col = DB::selectOne("SHOW COLUMNS FROM executions WHERE Field = 'status'");
        if ($col && str_contains(strtolower((string) $col->Type), 'enum')) {
            DB::statement("ALTER TABLE executions MODIFY `status` VARCHAR(255) NOT NULL DEFAULT 'draft'");
        }

        $m = $this;
        Schema::table('executions', function (Blueprint $table) use ($m) {
            if (! $m->indexExists('executions', 'executions_tenant_id_brand_id_index')) {
                $table->index(['tenant_id', 'brand_id']);
            }
            if (! $m->indexExists('executions', 'executions_category_id_index')) {
                $table->index('category_id');
            }
            if (! $m->indexExists('executions', 'executions_status_finalized_at_index')) {
                $table->index(['status', 'finalized_at']);
            }
        });
    }

    protected function refineExecutionAssets(): void
    {
        $m = $this;
        Schema::table('execution_assets', function (Blueprint $table) use ($m) {
            if (! $m->indexExists('execution_assets', 'execution_assets_execution_id_index')) {
                $table->index('execution_id');
            }
            if (! $m->indexExists('execution_assets', 'execution_assets_asset_id_index')) {
                $table->index('asset_id');
            }
            if (! $m->indexExists('execution_assets', 'execution_assets_execution_id_asset_id_unique')) {
                $table->unique(['execution_id', 'asset_id']);
            }
        });
    }

    protected function refineBrandIntelligenceScores(): void
    {
        if (! Schema::hasTable('brand_intelligence_scores')) {
            return;
        }

        $col = DB::selectOne("SHOW COLUMNS FROM brand_intelligence_scores WHERE Field = 'level'");
        if ($col && str_contains(strtolower((string) $col->Type), 'enum')) {
            DB::statement('ALTER TABLE brand_intelligence_scores MODIFY `level` VARCHAR(255) NULL');
        }

        if (! Schema::hasColumn('brand_intelligence_scores', 'engine_version')) {
            Schema::table('brand_intelligence_scores', function (Blueprint $table) {
                $table->string('engine_version')->nullable()->after('breakdown_json');
            });
        }
        // brand_id, execution_id, asset_id are indexed by foreign key constraints (InnoDB).
    }
};
