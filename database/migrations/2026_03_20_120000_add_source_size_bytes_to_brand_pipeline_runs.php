<?php

use App\Models\Asset;
use App\Models\BrandPipelineRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_pipeline_runs')) {
            return;
        }
        Schema::table('brand_pipeline_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('brand_pipeline_runs', 'source_size_bytes')) {
                $table->unsignedBigInteger('source_size_bytes')->nullable()->after('asset_id');
            }
        });

        if (! Schema::hasTable('assets')) {
            return;
        }

        BrandPipelineRun::query()
            ->whereNotNull('asset_id')
            ->whereNull('source_size_bytes')
            ->orderBy('id')
            ->chunkById(200, function ($runs) {
                foreach ($runs as $run) {
                    $bytes = Asset::where('id', $run->asset_id)->value('size_bytes');
                    if ($bytes !== null && (int) $bytes > 0) {
                        BrandPipelineRun::where('id', $run->id)->update(['source_size_bytes' => (int) $bytes]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('brand_pipeline_runs')) {
            return;
        }
        Schema::table('brand_pipeline_runs', function (Blueprint $table) {
            if (Schema::hasColumn('brand_pipeline_runs', 'source_size_bytes')) {
                $table->dropColumn('source_size_bytes');
            }
        });
    }
};
