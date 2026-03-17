<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_pipeline_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('brand_pipeline_runs', 'merged_extraction_json')) {
                $table->json('merged_extraction_json')->nullable()->after('error_message');
            }
            if (! Schema::hasColumn('brand_pipeline_runs', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brand_pipeline_runs', function (Blueprint $table) {
            if (Schema::hasColumn('brand_pipeline_runs', 'merged_extraction_json')) {
                $table->dropColumn('merged_extraction_json');
            }
            if (Schema::hasColumn('brand_pipeline_runs', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
        });
    }
};
