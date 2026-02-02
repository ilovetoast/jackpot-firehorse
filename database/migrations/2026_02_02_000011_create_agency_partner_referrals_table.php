<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase AG-10: Create agency partner referrals table.
 * 
 * Tracks referral-based client attributions separately from incubation.
 * One row per referred client tenant.
 * activated_at is set ONLY when client becomes paid + active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_partner_referrals', function (Blueprint $table) {
            $table->id();
            
            // Agency that referred the client
            $table->foreignId('agency_tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            
            // Client tenant that was referred
            $table->foreignId('client_tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            
            // Referral source (e.g., 'link', 'code', 'manual')
            $table->string('source')->nullable();
            
            // Set when client becomes active with billing
            $table->timestamp('activated_at')->nullable();
            
            // Optional link to ownership transfer (if activation via transfer)
            $table->foreignId('ownership_transfer_id')
                ->nullable()
                ->constrained('ownership_transfers')
                ->nullOnDelete();
            
            $table->timestamp('created_at')->useCurrent();
            
            // One referral record per client tenant
            $table->unique('client_tenant_id');
            
            // Index for agency dashboard queries
            $table->index(['agency_tenant_id', 'activated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_partner_referrals');
    }
};
