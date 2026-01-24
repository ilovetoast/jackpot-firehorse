<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if dimensions field already exists
        $existing = DB::table('metadata_fields')
            ->where('key', 'dimensions')
            ->where('scope', 'system')
            ->first();

        if (!$existing) {
            // Create dimensions field
            $fieldId = DB::table('metadata_fields')->insertGetId([
                'key' => 'dimensions',
                'system_label' => 'Dimensions',
                'type' => 'text',
                'scope' => 'system',
                'description' => 'Image dimensions in width×height format (e.g., 1920×1080)',
                'population_mode' => 'automatic',
                'show_on_upload' => false,
                'show_on_edit' => true,
                'show_in_filters' => true,
                'readonly' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->command->info("Created dimensions metadata field (ID: {$fieldId})");
        } else {
            $this->command->info("Dimensions metadata field already exists (ID: {$existing->id})");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('metadata_fields')
            ->where('key', 'dimensions')
            ->where('scope', 'system')
            ->delete();
    }
};
