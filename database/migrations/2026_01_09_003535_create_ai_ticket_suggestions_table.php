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
        Schema::create('ai_ticket_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->onDelete('cascade');
            $table->string('suggestion_type')->index(); // classification, duplicate, ticket_creation, severity
            $table->json('suggested_value'); // category, severity, component, etc.
            $table->decimal('confidence_score', 3, 2)->default(0.00); // 0.00 to 1.00
            $table->foreignId('ai_agent_run_id')->nullable()->constrained('ai_agent_runs')->onDelete('set null');
            $table->string('status')->default('pending')->index(); // pending, accepted, rejected, expired
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->json('metadata')->nullable(); // Additional context
            $table->timestamps();

            $table->index(['ticket_id', 'status']);
            $table->index(['ticket_id', 'suggestion_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_ticket_suggestions');
    }
};
