<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔒 Phase 4 Step 3 — Pattern Detection Rules
 * 
 * Consumes aggregates from locked phases only.
 * Must not modify event producers or aggregation logic.
 * 
 * Create Detection Rules Table
 * 
 * Stores declarative pattern detection rules for identifying
 * system health issues, tenant-specific failures, and cross-tenant anomalies.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('detection_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Human-readable rule name');
            $table->text('description')->nullable()->comment('Rule description and purpose');
            $table->string('event_type')->comment('Event type to evaluate (references EventType enum)');
            $table->enum('scope', ['global', 'tenant', 'asset', 'download'])->comment('Scope of detection (global, tenant, asset, download)');
            $table->unsignedBigInteger('threshold_count')->comment('Count threshold to trigger rule');
            $table->unsignedInteger('threshold_window_minutes')->comment('Time window in minutes to evaluate');
            $table->enum('comparison', ['greater_than', 'greater_than_or_equal'])->default('greater_than_or_equal')->comment('Comparison operator for threshold');
            $table->json('metadata_filters')->nullable()->comment('Optional metadata filters (e.g., error_code, file_type)');
            $table->enum('severity', ['info', 'warning', 'critical'])->default('warning')->comment('Severity level when rule matches');
            $table->boolean('enabled')->default(false)->comment('Whether rule is active');
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['enabled', 'scope', 'event_type']);
            $table->index('scope');
            $table->index('event_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detection_rules');
    }
};
