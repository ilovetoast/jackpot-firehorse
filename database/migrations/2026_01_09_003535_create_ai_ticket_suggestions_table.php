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
        if (Schema::hasTable('ai_ticket_suggestions')) {
            return;
        }

        Schema::create('ai_ticket_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->string('suggestion_type')->index(); // classification, duplicate, ticket_creation, severity
            $table->json('suggested_value'); // category, severity, component, etc.
            $table->decimal('confidence_score', 3, 2)->default(0.00); // 0.00 to 1.00
            $table->unsignedBigInteger('ai_agent_run_id')->nullable();
            $table->string('status')->default('pending')->index(); // pending, accepted, rejected, expired
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_by_user_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by_user_id')->nullable();
            $table->json('metadata')->nullable(); // Additional context
            $table->timestamps();

            $table->index(['ticket_id', 'status']);
            $table->index(['ticket_id', 'suggestion_type']);
        });

        // Add foreign key constraints only if referenced tables exist
        if (Schema::hasTable('tickets')) {
            Schema::table('ai_ticket_suggestions', function (Blueprint $table) {
                $table->foreign('ticket_id')->references('id')->on('tickets')->onDelete('cascade');
            });
        }
        if (Schema::hasTable('ai_agent_runs')) {
            Schema::table('ai_ticket_suggestions', function (Blueprint $table) {
                $table->foreign('ai_agent_run_id')->references('id')->on('ai_agent_runs')->onDelete('set null');
            });
        }
        if (Schema::hasTable('users')) {
            Schema::table('ai_ticket_suggestions', function (Blueprint $table) {
                $table->foreign('accepted_by_user_id')->references('id')->on('users')->onDelete('set null');
                $table->foreign('rejected_by_user_id')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_ticket_suggestions');
    }
};
