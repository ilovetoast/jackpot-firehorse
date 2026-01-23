<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Metadata Field AI Eligible Seeder
 *
 * Enables AI suggestions for system fields that should have AI suggestions by default.
 * Currently enables photo_type field for AI suggestions.
 */
class MetadataFieldAiEligibleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Fields that should have AI suggestions enabled by default
        $aiEligibleFields = [
            'photo_type',
        ];

        foreach ($aiEligibleFields as $fieldKey) {
            DB::table('metadata_fields')
                ->where('key', $fieldKey)
                ->update([
                    'ai_eligible' => true,
                    'updated_at' => now(),
                ]);
        }
    }
}
