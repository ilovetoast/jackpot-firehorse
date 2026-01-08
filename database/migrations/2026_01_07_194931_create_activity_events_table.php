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
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('brand_id')->nullable()->constrained()->onDelete('set null');
            
            // Actor information (polymorphic)
            $table->string('actor_type', 20); // user, system, api, guest
            $table->unsignedBigInteger('actor_id')->nullable();
            
            // Event information
            $table->string('event_type', 100)->index();
            
            // Subject information (polymorphic)
            $table->string('subject_type', 100)->index();
            $table->unsignedBigInteger('subject_id')->index();
            
            // Additional data
            $table->json('metadata')->nullable();
            
            // Request context (captured automatically when available)
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            
            // Timestamp (append-only, no updated_at)
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes for common queries
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'brand_id', 'created_at']);
            $table->index(['tenant_id', 'event_type', 'created_at']);
            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index(['actor_type', 'actor_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
