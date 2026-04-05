<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8: Tenant-scoped paid modules (Creator / Prostaff, future Shopify-style add-ons).
     * Storage add-ons remain on {@see tenants} (Stripe subscription items); this table is for feature modules.
     */
    public function up(): void
    {
        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('module_key'); // e.g. creator_module

            $table->string('status'); // active, trial, expired, cancelled

            $table->timestamp('expires_at')->nullable();

            $table->boolean('granted_by_admin')->default(false);

            $table->timestamps();

            $table->unique(['tenant_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
    }
};
