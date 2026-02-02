<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_agency')->default(false)->after('settings');
            $table->foreignId('agency_tier_id')->nullable()->constrained('agency_tiers')->nullOnDelete()->after('is_agency');
            $table->timestamp('agency_approved_at')->nullable()->after('agency_tier_id');
            $table->unsignedBigInteger('agency_approved_by')->nullable()->after('agency_approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['agency_tier_id']);
            $table->dropColumn(['is_agency', 'agency_tier_id', 'agency_approved_at', 'agency_approved_by']);
        });
    }
};
