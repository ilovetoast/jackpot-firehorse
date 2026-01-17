<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seed System Metadata Command
 *
 * Phase 2 – Step 9: Creates initial system metadata catalog.
 *
 * This command is idempotent and can be run multiple times safely.
 * It checks for existing fields before creating new ones.
 */
class SeedSystemMetadata extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'metadata:seed-system {--force : Force re-creation of existing fields}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed initial system metadata fields and options';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Seeding system metadata fields...');

        $force = $this->option('force');

        try {
            DB::beginTransaction();

            // Define all system metadata fields
            $fields = $this->getSystemFields();

            $createdCount = 0;
            $skippedCount = 0;
            $optionsCreatedCount = 0;

            foreach ($fields as $fieldDef) {
                $fieldKey = $fieldDef['key'];

                // Check if field already exists
                $existingField = DB::table('metadata_fields')
                    ->where('key', $fieldKey)
                    ->first();

                if ($existingField && !$force) {
                    $this->warn("Field '{$fieldKey}' already exists. Skipping. Use --force to recreate.");
                    $skippedCount++;
                    continue;
                }

                // If force and exists, we skip (immutability rule - cannot recreate)
                if ($existingField && $force) {
                    $this->warn("Field '{$fieldKey}' already exists. Cannot recreate (immutability rule). Skipping.");
                    $skippedCount++;
                    continue;
                }

                // Create field
                $fieldId = DB::table('metadata_fields')->insertGetId([
                    'key' => $fieldDef['key'],
                    'system_label' => $fieldDef['system_label'],
                    'type' => $fieldDef['type'],
                    'applies_to' => $fieldDef['applies_to'],
                    'scope' => 'system',
                    'is_filterable' => $fieldDef['is_filterable'],
                    'is_user_editable' => $fieldDef['is_user_editable'],
                    'is_ai_trainable' => $fieldDef['is_ai_trainable'],
                    'is_upload_visible' => $fieldDef['is_upload_visible'] ?? true,
                    'is_internal_only' => $fieldDef['is_internal_only'] ?? false,
                    'group_key' => $fieldDef['group_key'],
                    'plan_gate' => $fieldDef['plan_gate'] ?? null,
                    'deprecated_at' => null,
                    'replacement_field_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->info("Created field: {$fieldDef['key']} ({$fieldDef['system_label']})");
                $createdCount++;

                // Create options if provided
                if (isset($fieldDef['options']) && is_array($fieldDef['options'])) {
                    foreach ($fieldDef['options'] as $option) {
                        // Check if option already exists
                        $existingOption = DB::table('metadata_options')
                            ->where('metadata_field_id', $fieldId)
                            ->where('value', $option['value'])
                            ->first();

                        if ($existingOption) {
                            continue; // Skip existing options
                        }

                        DB::table('metadata_options')->insert([
                            'metadata_field_id' => $fieldId,
                            'value' => $option['value'],
                            'system_label' => $option['system_label'],
                            'is_system' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $optionsCreatedCount++;
                    }

                    $this->line("  Created " . count($fieldDef['options']) . " options");
                }
            }

            DB::commit();

            $this->newLine();
            $this->info("Seeding complete!");
            $this->info("Fields created: {$createdCount}");
            $this->info("Fields skipped: {$skippedCount}");
            $this->info("Options created: {$optionsCreatedCount}");

            Log::info('[SeedSystemMetadata] System metadata seeding completed', [
                'fields_created' => $createdCount,
                'fields_skipped' => $skippedCount,
                'options_created' => $optionsCreatedCount,
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Error seeding metadata: " . $e->getMessage());
            Log::error('[SeedSystemMetadata] Error seeding system metadata', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Get system metadata field definitions.
     *
     * @return array
     */
    protected function getSystemFields(): array
    {
        return [
            // 1. Creative / Descriptive
            [
                'key' => 'photo_type',
                'system_label' => 'Photo Type',
                'type' => 'select',
                'applies_to' => 'image',
                'group_key' => 'creative',
                'is_filterable' => true,
                'is_user_editable' => true,
                'is_ai_trainable' => true,
                'is_upload_visible' => true,
                'is_internal_only' => false,
                'options' => [
                    ['value' => 'studio', 'system_label' => 'Studio'],
                    ['value' => 'lifestyle', 'system_label' => 'Lifestyle'],
                    ['value' => 'product', 'system_label' => 'Product'],
                    ['value' => 'action', 'system_label' => 'Action'],
                    ['value' => 'flat_lay', 'system_label' => 'Flat Lay'],
                    ['value' => 'macro', 'system_label' => 'Macro'],
                ],
            ],
            [
                'key' => 'orientation',
                'system_label' => 'Orientation',
                'type' => 'select',
                'applies_to' => 'image',
                'group_key' => 'creative',
                'is_filterable' => true,
                'is_user_editable' => false,
                'is_ai_trainable' => true,
                'is_upload_visible' => true,
                'is_internal_only' => false,
                'options' => [
                    ['value' => 'landscape', 'system_label' => 'Landscape'],
                    ['value' => 'portrait', 'system_label' => 'Portrait'],
                    ['value' => 'square', 'system_label' => 'Square'],
                ],
            ],

            // 2. Technical
            [
                'key' => 'color_space',
                'system_label' => 'Color Space',
                'type' => 'select',
                'applies_to' => 'image',
                'group_key' => 'technical',
                'is_filterable' => true,
                'is_user_editable' => false,
                'is_ai_trainable' => false,
                'is_upload_visible' => true,
                'is_internal_only' => false,
                'options' => [
                    ['value' => 'srgb', 'system_label' => 'sRGB'],
                    ['value' => 'adobe_rgb', 'system_label' => 'Adobe RGB'],
                    ['value' => 'display_p3', 'system_label' => 'Display P3'],
                ],
            ],
            [
                'key' => 'resolution_class',
                'system_label' => 'Resolution Class',
                'type' => 'select',
                'applies_to' => 'image',
                'group_key' => 'technical',
                'is_filterable' => true,
                'is_user_editable' => false,
                'is_ai_trainable' => false,
                'is_upload_visible' => true,
                'is_internal_only' => false,
                'options' => [
                    ['value' => 'low', 'system_label' => 'Low'],
                    ['value' => 'medium', 'system_label' => 'Medium'],
                    ['value' => 'high', 'system_label' => 'High'],
                    ['value' => 'ultra', 'system_label' => 'Ultra'],
                ],
            ],

            // 3. Rights / Legal
            [
                'key' => 'usage_rights',
                'system_label' => 'Usage Rights',
                'type' => 'select',
                'applies_to' => 'all',
                'group_key' => 'legal',
                'is_filterable' => true,
                'is_user_editable' => true,
                'is_ai_trainable' => false,
                'is_upload_visible' => true,
                'is_internal_only' => false,
                'options' => [
                    ['value' => 'unrestricted', 'system_label' => 'Unrestricted'],
                    ['value' => 'editorial_only', 'system_label' => 'Editorial Only'],
                    ['value' => 'internal_use', 'system_label' => 'Internal Use'],
                    ['value' => 'licensed', 'system_label' => 'Licensed'],
                    ['value' => 'restricted', 'system_label' => 'Restricted'],
                ],
            ],
            [
                'key' => 'expiration_date',
                'system_label' => 'Expiration Date',
                'type' => 'date',
                'applies_to' => 'all',
                'group_key' => 'legal',
                'is_filterable' => true,
                'is_user_editable' => true,
                'is_ai_trainable' => false,
                'is_upload_visible' => true,
                'is_internal_only' => false,
            ],

            // 4. Quality / Internal
            [
                'key' => 'quality_rating',
                'system_label' => 'Quality Rating',
                'type' => 'rating',
                'applies_to' => 'all',
                'group_key' => 'internal',
                'is_filterable' => false,
                'is_user_editable' => true,
                'is_ai_trainable' => false,
                'is_upload_visible' => false, // Rating fields excluded from upload
                'is_internal_only' => true,
            ],

            // 5. General
            [
                'key' => 'campaign',
                'system_label' => 'Campaign',
                'type' => 'text',
                'applies_to' => 'all',
                'group_key' => 'general',
                'is_filterable' => true,
                'is_user_editable' => true,
                'is_ai_trainable' => false,
                'is_upload_visible' => true,
                'is_internal_only' => false,
            ],
        ];
    }
}
