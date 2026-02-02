<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Phase AG-5: Track agency partner access to client tenants.
     * This table provides an audit trail for agency access grants.
     */
    public function up(): void
    {
        Schema::create('agency_partner_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Agency user granted access
            $table->foreignId('ownership_transfer_id')->nullable()->constrained('ownership_transfers')->nullOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            
            // One active access per user per client tenant
            $table->unique(['user_id', 'client_tenant_id', 'revoked_at'], 'unique_active_access');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agency_partner_access');
    }
};
