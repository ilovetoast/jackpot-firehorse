<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add analysis_status to assets table.
     *
     * Represents pipeline progress. Values:
     * - uploading
     * - generating_thumbnails
     * - extracting_metadata
     * - generating_embedding
     * - scoring
     * - complete
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('analysis_status', 32)->default('uploading')->after('thumbnail_status')->index();
        });

        // Backfill: assets with evaluated compliance score are complete
        DB::table('assets')
            ->whereIn('id', function ($q) {
                $q->select('asset_id')
                    ->from('brand_compliance_scores')
                    ->where('evaluation_status', 'evaluated');
            })
            ->update(['analysis_status' => 'complete']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['analysis_status']);
            $table->dropColumn('analysis_status');
        });
    }
};
