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
        Schema::create('agency_partner_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ownership_transfer_id')->constrained('ownership_transfers')->cascadeOnDelete();
            $table->string('reward_type'); // e.g. "company_activation"
            $table->decimal('reward_value', 10, 2)->nullable(); // For future use
            $table->timestamp('created_at')->useCurrent();
            
            // Ensure one reward per transfer (idempotency)
            $table->unique('ownership_transfer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agency_partner_rewards');
    }
};
