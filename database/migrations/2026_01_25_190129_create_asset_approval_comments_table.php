<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase AF-2: Create asset_approval_comments table.
 * 
 * Stores full approval history with comments tied to actions.
 * Immutable audit trail for approval workflow.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // If table exists from previous failed migration, drop it first
        // This is safe since this is a new feature with no production data
        if (Schema::hasTable('asset_approval_comments')) {
            Schema::dropIfExists('asset_approval_comments');
        }
        
        Schema::create('asset_approval_comments', function (Blueprint $table) {
            $table->id();
            // Assets table uses UUID primary key, not auto-incrementing integer
            $table->uuid('asset_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('action', ['submitted', 'approved', 'rejected', 'resubmitted', 'comment']);
            $table->text('comment')->nullable();
            $table->timestamps();

            // Foreign key constraint for UUID asset_id
            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->onDelete('cascade');

            $table->index('asset_id');
            $table->index('user_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_approval_comments');
    }
};
