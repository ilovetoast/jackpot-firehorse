<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add evaluation_status to brand_compliance_scores for explicit evaluation state.
     * Values: pending, evaluated, incomplete, not_applicable
     */
    public function up(): void
    {
        Schema::table('brand_compliance_scores', function (Blueprint $table) {
            $table->string('evaluation_status')
                ->default('pending')
                ->after('breakdown_payload')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brand_compliance_scores', function (Blueprint $table) {
            $table->dropIndex(['evaluation_status']);
            $table->dropColumn('evaluation_status');
        });
    }
};
