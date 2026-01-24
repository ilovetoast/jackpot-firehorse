<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase J.2.2: Tenant-level AI tagging controls
     * 
     * This table stores tenant-scoped AI tagging policy settings to enable
     * granular control over AI tagging behavior without modifying the core
     * AI generation pipeline.
     */
    public function up(): void
    {
        Schema::create('tenant_ai_tag_settings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned();
            
            // Master toggle (Hard Stop)
            $table->boolean('disable_ai_tagging')->default(false);
            
            // Suggestion controls
            $table->boolean('enable_ai_tag_suggestions')->default(true);
            
            // Auto-apply controls (OFF by default per requirement)
            $table->boolean('enable_ai_tag_auto_apply')->default(false);
            
            // Quantity cap controls
            $table->enum('ai_auto_tag_limit_mode', ['best_practices', 'custom'])->default('best_practices');
            $table->integer('ai_auto_tag_limit_value')->nullable(); // Used when mode = 'custom'
            
            $table->timestamps();

            // Foreign key constraint for tenant_id
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            // Indexes for fast lookups
            $table->index('tenant_id');
            $table->index(['tenant_id', 'disable_ai_tagging']);
            $table->index(['tenant_id', 'enable_ai_tag_auto_apply']);

            // Unique constraint: one settings record per tenant
            $table->unique('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_tag_settings');
    }
};