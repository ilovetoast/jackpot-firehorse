<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase AF-3: Create notifications table for in-app approval notifications.
 * 
 * Notifications are immutable except for read_at.
 * Data field stores JSON payload (email-safe for future use).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // e.g., 'asset.submitted', 'asset.approved', 'asset.rejected', 'asset.resubmitted'
            $table->json('data'); // Email-safe payload: asset name, brand name, action, actor
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('type');
            $table->index('read_at');
            $table->index(['user_id', 'read_at']); // For unread count queries
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
