<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent storage for pulled Sentry issues (system-level, no tenant_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sentry_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sentry_issue_id')->index();
            $table->string('environment')->index();
            $table->string('level');
            $table->string('title');
            $table->string('fingerprint')->nullable();
            $table->unsignedInteger('occurrence_count')->default(0);
            $table->timestamp('first_seen')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->longText('stack_trace')->nullable();
            $table->longText('ai_summary')->nullable();
            $table->longText('ai_root_cause')->nullable();
            $table->longText('ai_fix_suggestion')->nullable();
            $table->enum('status', ['open', 'dismissed', 'resolved'])->default('open');
            $table->boolean('selected_for_heal')->default(false);
            $table->boolean('auto_heal_attempted')->default(false);
            $table->unsignedInteger('ai_token_input')->nullable();
            $table->unsignedInteger('ai_token_output')->nullable();
            $table->decimal('ai_cost', 10, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sentry_issues');
    }
};
