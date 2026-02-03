<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A-1: Add observability fields to ai_agent_runs.
 *
 * Supports: Did the agent run? What did it conclude? Was escalation recommended?
 * DO NOT log prompts or tokens beyond existing columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->string('agent_name')->nullable()->after('agent_id');
            $table->string('entity_type', 50)->nullable()->after('task_type')->index();
            $table->string('entity_id')->nullable()->after('entity_type')->index();
            $table->string('severity', 50)->nullable()->after('status')->index();
            $table->decimal('confidence', 3, 2)->nullable()->after('severity');
            $table->text('summary')->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('ai_agent_runs', function (Blueprint $table) {
            $table->dropColumn(['agent_name', 'entity_type', 'entity_id', 'severity', 'confidence', 'summary']);
        });
    }
};
