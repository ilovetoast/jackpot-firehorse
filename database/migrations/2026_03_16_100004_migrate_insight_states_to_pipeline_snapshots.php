<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_pipeline_snapshots')) {
            return;
        }

        Schema::table('brand_model_version_insight_states', function (Blueprint $table) {
            if (! Schema::hasColumn('brand_model_version_insight_states', 'source_pipeline_snapshot_id')) {
                $table->unsignedBigInteger('source_pipeline_snapshot_id')->nullable()->after('brand_model_version_id');
                $table->foreign('source_pipeline_snapshot_id', 'bmv_insight_ps_fk')
                    ->references('id')
                    ->on('brand_pipeline_snapshots')
                    ->nullOnDelete();
            }
        });

        if (Schema::hasTable('brand_research_snapshots')) {
            $oldSnapshots = DB::table('brand_research_snapshots')->get();
            $mapping = [];

            foreach ($oldSnapshots as $old) {
                $newId = DB::table('brand_pipeline_snapshots')->insertGetId([
                    'brand_pipeline_run_id' => null,
                    'brand_id' => $old->brand_id,
                    'brand_model_version_id' => $old->brand_model_version_id,
                    'snapshot' => $old->snapshot,
                    'suggestions' => $old->suggestions,
                    'coherence' => $old->coherence,
                    'alignment' => $old->alignment,
                    'report' => $old->report,
                    'sections_json' => $old->sections_json,
                    'status' => $old->status,
                    'source_url' => $old->source_url,
                    'created_at' => $old->created_at,
                    'updated_at' => $old->updated_at,
                ]);
                $mapping[$old->id] = $newId;
            }

            foreach ($mapping as $oldId => $newId) {
                DB::table('brand_model_version_insight_states')
                    ->where('source_snapshot_id', $oldId)
                    ->update(['source_pipeline_snapshot_id' => $newId]);
            }
        }

        if (Schema::hasColumn('brand_model_version_insight_states', 'source_snapshot_id')) {
            $db = DB::getDatabaseName();
            $fk = DB::selectOne("
                SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = 'brand_model_version_insight_states'
                AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME LIKE '%snapshot%'
            ", [$db]);
            if ($fk && ! empty($fk->CONSTRAINT_NAME)) {
                DB::statement('ALTER TABLE brand_model_version_insight_states DROP FOREIGN KEY `' . $fk->CONSTRAINT_NAME . '`');
            }
            Schema::table('brand_model_version_insight_states', function (Blueprint $table) {
                $table->dropColumn('source_snapshot_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('brand_model_version_insight_states', function (Blueprint $table) {
            if (Schema::hasColumn('brand_model_version_insight_states', 'source_pipeline_snapshot_id')) {
                $table->dropForeign('bmv_insight_ps_fk');
                $table->dropColumn('source_pipeline_snapshot_id');
            }
            if (! Schema::hasColumn('brand_model_version_insight_states', 'source_snapshot_id') && Schema::hasTable('brand_research_snapshots')) {
                $table->foreignId('source_snapshot_id')->nullable()
                    ->constrained('brand_research_snapshots')
                    ->nullOnDelete();
            }
        });
    }
};
