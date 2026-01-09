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
        // Skip if table already exists (handles case where migration partially ran before)
        if (Schema::hasTable('frontend_errors')) {
            return;
        }

        Schema::create('frontend_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('error_type')->index(); // Error type (e.g., TypeError, ReferenceError)
            $table->text('message');
            $table->text('stack_trace')->nullable();
            $table->string('url')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable(); // Additional metadata
            $table->timestamps();
            
            // Additional indexes (error_type already indexed inline above)
            $table->index('tenant_id');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('frontend_errors');
    }
};
