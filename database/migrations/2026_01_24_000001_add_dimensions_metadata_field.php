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
                'applies_to' => 'image',
                'scope' => 'system',
                'is_filterable' => true,
                'is_user_editable' => true,
                'is_ai_trainable' => false,
                'is_upload_visible' => false,
                'is_internal_only' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Dimensions metadata field created
        } else {
            // Dimensions metadata field already exists
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
