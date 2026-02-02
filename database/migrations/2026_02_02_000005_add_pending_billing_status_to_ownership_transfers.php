<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Phase AG-3: Add 'pending_billing' status to ownership_transfers
        // MySQL ENUM requires ALTER TABLE to add new values
        DB::statement("ALTER TABLE ownership_transfers MODIFY COLUMN status ENUM('pending', 'confirmed', 'accepted', 'pending_billing', 'completed', 'cancelled') NOT NULL DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'pending_billing' status
        DB::statement("ALTER TABLE ownership_transfers MODIFY COLUMN status ENUM('pending', 'confirmed', 'accepted', 'completed', 'cancelled') NOT NULL DEFAULT 'pending'");
    }
};
