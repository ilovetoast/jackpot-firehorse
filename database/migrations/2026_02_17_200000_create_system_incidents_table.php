<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified operational incident and asset-processing integrity layer.
 *
 * Tracks incidents from assets, derivatives, jobs, scheduler, storage, AI, system.
 * DB is source of truth. No logging-only logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_type', 50)->index(); // asset, derivative, job, scheduler, storage, ai, system
            $table->uuid('source_id')->nullable()->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('severity', 20)->index(); // warning, error, critical
            $table->string('title');
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('retryable')->default(false);
            $table->boolean('requires_support')->default(false);
            $table->boolean('auto_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamp('detected_at')->index();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_incidents');
    }
};
