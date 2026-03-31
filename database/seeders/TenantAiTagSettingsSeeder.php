<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\AiTagPolicyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Tenant AI Tag Settings Seeder
 *
 * Initializes AI tagging settings for tenants missing a row (auto-apply up to best-practices cap by default).
 * Uses AiTagPolicyService to ensure consistency with the service's default settings.
 */
class TenantAiTagSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $service = app(AiTagPolicyService::class);
        $defaultSettings = $service->getDefaultSettings();

        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            // Check if settings already exist for this tenant
            $existing = DB::table('tenant_ai_tag_settings')
                ->where('tenant_id', $tenant->id)
                ->first();

            if (!$existing) {
                // Insert default settings for this tenant
                DB::table('tenant_ai_tag_settings')->insert([
                    'tenant_id' => $tenant->id,
                    'disable_ai_tagging' => $defaultSettings['disable_ai_tagging'],
                    'enable_ai_tag_suggestions' => $defaultSettings['enable_ai_tag_suggestions'],
                    'enable_ai_tag_auto_apply' => $defaultSettings['enable_ai_tag_auto_apply'],
                    'ai_auto_tag_limit_mode' => $defaultSettings['ai_auto_tag_limit_mode'],
                    'ai_auto_tag_limit_value' => $defaultSettings['ai_auto_tag_limit_value'],
                    'ai_best_practices_limit' => $defaultSettings['ai_best_practices_limit'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
