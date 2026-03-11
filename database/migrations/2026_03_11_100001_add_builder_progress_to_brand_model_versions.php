<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_model_versions', function (Blueprint $table) {
            $table->json('builder_progress')->nullable()->after('metrics_payload');
        });
    }

    public function down(): void
    {
        Schema::table('brand_model_versions', function (Blueprint $table) {
            $table->dropColumn('builder_progress');
        });
    }
};
