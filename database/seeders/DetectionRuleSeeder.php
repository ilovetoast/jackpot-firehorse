<?php

namespace Database\Seeders;

use App\Models\DetectionRule;
use Illuminate\Database\Seeder;

/**
 * Detection Rule Seeder
 * 
 * Seeds example pattern detection rules (disabled by default).
 * Rules can be enabled individually via admin or configuration.
 * 
 * 🔒 Phase 4 Step 3 — Pattern Detection Rules
 */
class DetectionRuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rules = [
            [
                'name' => 'High Download ZIP Failure Rate (Tenant)',
                'description' => 'Triggers when a tenant has 5 or more download ZIP failures within a 15-minute window. Indicates potential issues with ZIP generation for that tenant.',
                'event_type' => \App\Enums\EventType::DOWNLOAD_ZIP_FAILED,
                'scope' => 'tenant',
                'threshold_count' => 5,
                'threshold_window_minutes' => 15,
                'comparison' => 'greater_than_or_equal',
                'metadata_filters' => null,
                'severity' => 'warning',
                'enabled' => false,
            ],
            [
                'name' => 'Global ZIP Generation Failure Rate',
                'description' => 'Triggers when ZIP generation failure rate exceeds 20% across all tenants within a 1-hour window. Indicates systemic issues with ZIP generation infrastructure.',
                'event_type' => \App\Enums\EventType::DOWNLOAD_ZIP_FAILED,
                'scope' => 'global',
                'threshold_count' => 20, // 20 failures
                'threshold_window_minutes' => 60,
                'comparison' => 'greater_than_or_equal',
                'metadata_filters' => null,
                'severity' => 'critical',
                'enabled' => false,
            ],
            [
                'name' => 'Repeated Asset Download Failures',
                'description' => 'Triggers when a specific asset has 3 or more download failures within a 30-minute window. May indicate asset-specific issues (corrupted file, S3 access problems).',
                'event_type' => \App\Enums\EventType::ASSET_DOWNLOAD_FAILED,
                'scope' => 'asset',
                'threshold_count' => 3,
                'threshold_window_minutes' => 30,
                'comparison' => 'greater_than_or_equal',
                'metadata_filters' => null,
                'severity' => 'warning',
                'enabled' => false,
            ],
            [
                'name' => 'High Upload Validation Errors (Tenant)',
                'description' => 'Triggers when a tenant has 10 or more upload validation errors within a 15-minute window. May indicate client-side issues or configuration problems.',
                'event_type' => \App\Enums\EventType::ASSET_UPLOAD_FINALIZED,
                'scope' => 'tenant',
                'threshold_count' => 10,
                'threshold_window_minutes' => 15,
                'comparison' => 'greater_than_or_equal',
                'metadata_filters' => [
                    'error_codes' => 'UPLOAD_VALIDATION_FAILED', // Filter by error code (stored in metadata.error_codes array)
                ],
                'severity' => 'info',
                'enabled' => false,
            ],
            [
                'name' => 'Thumbnail Generation Failures (Tenant)',
                'description' => 'Triggers when a tenant has 10 or more thumbnail generation failures within a 1-hour window. May indicate image processing pipeline issues for that tenant.',
                'event_type' => \App\Enums\EventType::ASSET_THUMBNAIL_FAILED,
                'scope' => 'tenant',
                'threshold_count' => 10,
                'threshold_window_minutes' => 60,
                'comparison' => 'greater_than_or_equal',
                'metadata_filters' => null,
                'severity' => 'warning',
                'enabled' => false,
            ],
            [
                'name' => 'Download Group Creation Failures (Global)',
                'description' => 'Triggers when download group creation failures exceed 5 within a 15-minute window globally. Indicates potential issues with download group creation logic or database constraints.',
                'event_type' => \App\Enums\EventType::DOWNLOAD_GROUP_FAILED,
                'scope' => 'global',
                'threshold_count' => 5,
                'threshold_window_minutes' => 15,
                'comparison' => 'greater_than_or_equal',
                'metadata_filters' => null,
                'severity' => 'critical',
                'enabled' => false,
            ],
        ];

        foreach ($rules as $ruleData) {
            DetectionRule::firstOrCreate(
                [
                    'name' => $ruleData['name'],
                    'event_type' => $ruleData['event_type'],
                    'scope' => $ruleData['scope'],
                ],
                $ruleData
            );
        }
    }
}
