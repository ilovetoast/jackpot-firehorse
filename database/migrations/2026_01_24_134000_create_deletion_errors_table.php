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
        Schema::create('deletion_errors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('asset_id')->index(); // Asset UUID that failed to delete
            $table->string('original_filename')->nullable();
            $table->enum('deletion_type', ['soft', 'hard'])->default('hard');
            $table->string('error_type'); // categorized error type
            $table->text('error_message'); // original error message
            $table->json('error_details')->nullable(); // additional error context
            $table->integer('attempts')->default(1); // number of retry attempts
            
            // Resolution tracking
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('resolution_notes')->nullable();
            
            $table->timestamps();

            // Indexes
            $table->index(['tenant_id', 'resolved_at']); // For filtering unresolved errors by tenant
            $table->index(['error_type', 'created_at']); // For error type analysis
            $table->index('created_at'); // For chronological sorting

            // Foreign key constraints
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deletion_errors');
    }
};