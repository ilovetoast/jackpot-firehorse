<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make avg_score nullable when no execution alignment data exists.
     */
    public function up(): void
    {
        Schema::table('brand_compliance_aggregates', function (Blueprint $table) {
            $table->decimal('avg_score', 5, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('brand_compliance_aggregates', function (Blueprint $table) {
            $table->decimal('avg_score', 5, 2)->default(0)->change();
        });
    }
};
