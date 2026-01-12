<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalize Asset Status to Visibility-Based System
 * 
 * This migration converts old lifecycle statuses to the new visibility-based system:
 * - INITIATED, UPLOADING, UPLOADED, PROCESSING, THUMBNAIL_GENERATED, AI_TAGGED, COMPLETED → VISIBLE
 * - FAILED → FAILED (unchanged)
 * 
 * This ensures no assets disappear after the refactor.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Normalize all lifecycle statuses to VISIBLE
        // This includes: initiated, uploading, uploaded, processing, thumbnail_generated, ai_tagged, completed
        DB::table('assets')
            ->whereIn('status', [
                'initiated',
                'uploading',
                'uploaded',
                'processing',
                'thumbnail_generated',
                'ai_tagged',
                'completed',
            ])
            ->update(['status' => 'visible']);
        
        // FAILED status remains unchanged (already correct)
    }

    /**
     * Reverse the migrations.
     * 
     * Note: This is a one-way migration. Reverting would require guessing
     * which assets were in which lifecycle state, which is not possible.
     */
    public function down(): void
    {
        // Cannot reliably revert - would require storing original statuses
        // This is a breaking change migration
        throw new \RuntimeException('This migration cannot be reversed. It is a breaking change migration.');
    }
};
