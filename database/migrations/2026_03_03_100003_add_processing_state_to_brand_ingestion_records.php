<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_ingestion_records', function (Blueprint $table) {
            if (! Schema::hasColumn('brand_ingestion_records', 'processing_state')) {
                $table->json('processing_state')->nullable()->after('extraction_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brand_ingestion_records', function (Blueprint $table) {
            if (Schema::hasColumn('brand_ingestion_records', 'processing_state')) {
                $table->dropColumn('processing_state');
            }
        });
    }
};
