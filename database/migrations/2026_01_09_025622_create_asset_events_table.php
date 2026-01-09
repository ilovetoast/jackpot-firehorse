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
        Schema::create('asset_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('brand_id')->nullable()->constrained()->onDelete('cascade');
            $table->uuid('asset_id');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('event_type');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            // Foreign key constraints
            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->onDelete('cascade');

            // Indexes
            $table->index('tenant_id');
            $table->index('brand_id');
            $table->index('asset_id');
            $table->index('user_id');
            $table->index('event_type');
            $table->index('created_at');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['asset_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_events');
    }
};
