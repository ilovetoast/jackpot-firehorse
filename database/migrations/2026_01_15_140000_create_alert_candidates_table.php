<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔒 Phase 4 Step 4 — Alert Candidate Generation
 * 
 * Consumes pattern detection results from locked phases only.
 * Must not modify detection rules, aggregation logic, or event producers.
 * 
 * Create Alert Candidates Table
 * 
 * Stores alert candidates representing detected anomalous conditions.
 * These records are persisted for review, suppression, escalation, or AI explanation.
 * NO notifications or alerts are sent from this step.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('alert_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('detection_rules')->onDelete('cascade')->comment('Detection rule that triggered this alert');
            $table->enum('scope', ['global', 'tenant', 'asset', 'download'])->comment('Scope of the alert (matches detection rule scope)');
            $table->string('subject_id', 36)->nullable()->comment('ID of the subject (tenant_id, asset_id, download_id, or null for global)');
            $table->unsignedBigInteger('tenant_id')->nullable()->index()->comment('Tenant ID if applicable (null for global scope)');
            $table->enum('severity', ['info', 'warning', 'critical'])->default('warning')->comment('Severity level');
            $table->unsignedBigInteger('observed_count')->comment('Observed event count that triggered the alert');
            $table->unsignedBigInteger('threshold_count')->comment('Threshold count from the detection rule');
            $table->unsignedInteger('window_minutes')->comment('Time window in minutes from the detection rule');
            $table->enum('status', ['open', 'acknowledged', 'resolved'])->default('open')->comment('Alert status (open, acknowledged, resolved)');
            $table->timestamp('first_detected_at')->comment('When this alert was first detected');
            $table->timestamp('last_detected_at')->comment('When this alert was last detected (updated on repeat detections)');
            $table->unsignedInteger('detection_count')->default(1)->comment('Number of times this alert has been detected (incremented on repeat detections)');
            $table->json('context')->nullable()->comment('Additional context from pattern detection (metadata_summary, etc.)');
            $table->timestamps();

            // Unique constraint: one open alert per rule+scope+subject combination
            // Allows multiple alerts if status is acknowledged or resolved
            // Note: MySQL treats NULL values specially in unique constraints, so null subject_ids can coexist
            $table->unique(['rule_id', 'scope', 'subject_id', 'status'], 'alert_candidates_unique_open');
            
            // Indexes for efficient querying
            $table->index(['status', 'severity']);
            $table->index(['tenant_id', 'status']);
            $table->index(['rule_id', 'status']);
            $table->index('first_detected_at');
            $table->index('last_detected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_candidates');
    }
};
