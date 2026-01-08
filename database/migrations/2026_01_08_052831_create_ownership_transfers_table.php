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
        Schema::create('ownership_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'confirmed', 'accepted', 'completed', 'cancelled'])->default('pending');
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('tenant_id');
            $table->index('status');
            $table->index('from_user_id');
            $table->index('to_user_id');
            
            // Note: Unique constraint for active transfers is enforced at application level
            // Database-level unique constraints with WHERE clauses are not supported in all DB engines
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ownership_transfers');
    }
};
