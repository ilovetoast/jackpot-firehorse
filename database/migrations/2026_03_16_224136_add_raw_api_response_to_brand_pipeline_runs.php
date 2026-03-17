<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_pipeline_runs', function (Blueprint $table) {
            $table->json('raw_api_response_json')->nullable()->after('merged_extraction_json');
        });
    }

    public function down(): void
    {
        Schema::table('brand_pipeline_runs', function (Blueprint $table) {
            $table->dropColumn('raw_api_response_json');
        });
    }
};
