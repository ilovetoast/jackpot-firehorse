<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase J.2.1: Tag normalization supporting tables
     * 
     * This table stores tenant-scoped tag rules for blocking unwanted tags
     * and marking preferred tags for future prioritization.
     */
    public function up(): void
    {
        Schema::create('tag_rules', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('tag', 64); // The canonical tag this rule applies to
            $table->enum('rule_type', ['block', 'preferred']); // Rule type
            $table->text('notes')->nullable(); // Optional admin notes
            $table->timestamps();

            // Foreign key constraint for tenant_id
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            // Indexes for fast lookups
            $table->index('tenant_id');
            $table->index(['tenant_id', 'rule_type']);
            $table->index(['tenant_id', 'tag']);
            $table->index(['tenant_id', 'tag', 'rule_type']);

            // Unique constraint: one rule per tag per tenant (can't be both blocked AND preferred)
            $table->unique(['tenant_id', 'tag']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tag_rules');
    }
};