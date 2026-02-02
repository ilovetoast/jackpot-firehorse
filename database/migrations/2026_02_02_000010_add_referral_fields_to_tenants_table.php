<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase AG-10: Add referral attribution fields to tenants table.
 * 
 * These fields track which agency referred a client tenant.
 * They are informational only and do NOT imply incubation.
 * They do NOT trigger rewards on their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Phase AG-10: Referral attribution (distinct from incubation)
            $table->foreignId('referred_by_agency_id')
                ->nullable()
                ->after('incubated_by_agency_id')
                ->constrained('tenants')
                ->nullOnDelete();
            
            $table->string('referral_source')->nullable()->after('referred_by_agency_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['referred_by_agency_id']);
            $table->dropColumn(['referred_by_agency_id', 'referral_source']);
        });
    }
};
