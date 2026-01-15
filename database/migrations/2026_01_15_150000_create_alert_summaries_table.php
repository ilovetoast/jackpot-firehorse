<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔒 Phase 4 Step 5 — AI Summaries for Alert Candidates
 * 
 * Consumes alert candidates from locked phases only.
 * Must not modify alert candidate lifecycle, detection rules, or aggregation logic.
 * 
 * Create Alert Summaries Table
 * 
 * Stores AI-generated summaries for alert candidates that explain what is happening,
 * who is affected, severity, and suggested next steps.
 * 
 * NO ACTIONS — summaries are for human consumption only.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('alert_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_candidate_id')->constrained('alert_candidates')->onDelete('cascade')->unique()->comment('One summary per alert candidate');
            $table->text('summary_text')->comment('Human-readable summary explaining the alert');
            $table->text('impact_summary')->nullable()->comment('Summary of who/what is affected');
            $table->string('affected_scope')->nullable()->comment('Description of affected entities (e.g., "Tenant ABC", "Asset XYZ")');
            $table->enum('severity', ['info', 'warning', 'critical'])->comment('Severity level (copied from alert candidate)');
            $table->json('suggested_actions')->nullable()->comment('JSON array of suggested next steps (non-binding recommendations)');
            $table->decimal('confidence_score', 3, 2)->default(0.80)->comment('AI confidence score (0.00-1.00)');
            $table->timestamp('generated_at')->comment('When the summary was generated');
            $table->timestamps();

            // Indexes for efficient querying
            $table->index('alert_candidate_id');
            $table->index('severity');
            $table->index('generated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_summaries');
    }
};
