<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates table to track AI usage by feature (tagging, suggestions) per tenant.
     * Used to enforce monthly caps and prevent runaway AI costs.
     */
    public function up(): void
    {
        if (Schema::hasTable('ai_usage')) {
            return;
        }

        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('feature'); // 'tagging', 'suggestions'
            $table->date('usage_date'); // Date of usage (for monthly aggregation)
            $table->integer('call_count')->default(1); // Number of AI calls
            $table->timestamps();

            // Indexes for efficient queries
            $table->index(['tenant_id', 'feature', 'usage_date']);
            $table->index(['tenant_id', 'usage_date']);
            $table->index('usage_date');

            // Unique constraint: one record per tenant/feature/date
            $table->unique(['tenant_id', 'feature', 'usage_date'], 'ai_usage_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
