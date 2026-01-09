<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create system user for automation/AI agent ticket creation
        // Use a special email and mark as system user
        // Note: MySQL doesn't allow ID 0 for auto-increment, so we'll use a high number
        // Check if system user already exists
        $existing = DB::table('users')->where('email', 'system@internal')->first();
        
        if (!$existing) {
            // Get the next available ID (use a high number to avoid conflicts)
            $maxId = DB::table('users')->max('id') ?? 0;
            $systemUserId = max(999999, $maxId + 1000);
            
            DB::table('users')->insert([
                'id' => $systemUserId,
                'first_name' => 'System',
                'last_name' => 'User',
                'email' => 'system@internal',
                'password' => Hash::make(Str::random(64)), // Random password, never used
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')->where('email', 'system@internal')->delete();
    }
};
