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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number')->unique()->index(); // Public-facing number (e.g., SUP-1042)
            $table->string('type')->index(); // tenant, tenant_internal, internal
            $table->string('status')->index(); // open, waiting_on_user, waiting_on_support, in_progress, blocked, resolved, closed
            $table->foreignId('tenant_id')->nullable()->constrained()->onDelete('cascade'); // Optional tenant association
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('restrict'); // User who created the ticket
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->onDelete('set null'); // Assigned user
            $table->string('assigned_team')->nullable()->index(); // support, admin, engineering
            $table->string('sla_plan_id')->nullable(); // Future: SLA plan reference
            $table->timestamp('first_response_at')->nullable(); // Future: SLA tracking
            $table->timestamp('resolved_at')->nullable(); // Future: SLA tracking
            $table->json('metadata')->nullable(); // Structured metadata for future AI/automation
            $table->timestamps();

            // Indexes
            $table->index('tenant_id');
            $table->index('assigned_to_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
