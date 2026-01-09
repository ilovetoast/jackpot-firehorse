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
        if (Schema::hasTable('error_logs')) {
            return;
        }

        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('level')->index(); // error, warning, critical
            $table->text('message');
            $table->json('context')->nullable(); // Additional context data
            $table->string('file')->nullable();
            $table->integer('line')->nullable();
            $table->text('trace')->nullable(); // Stack trace
            $table->timestamps();
            
            // Additional indexes (level already indexed inline above)
            $table->index('tenant_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
