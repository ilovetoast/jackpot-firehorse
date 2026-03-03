<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_ingestion_records', function (Blueprint $table) {
            if (! Schema::hasColumn('brand_ingestion_records', 'failure_reason')) {
                $table->text('failure_reason')->nullable()->after('extraction_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brand_ingestion_records', function (Blueprint $table) {
            if (Schema::hasColumn('brand_ingestion_records', 'failure_reason')) {
                $table->dropColumn('failure_reason');
            }
        });
    }
};
