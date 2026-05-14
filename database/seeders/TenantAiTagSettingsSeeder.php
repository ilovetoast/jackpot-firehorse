<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\AiTagPolicyService;
use App\Services\PlanService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Tenant AI Tag Settings Seeder
 *
 * Initializes AI tagging settings for tenants missing a row. Defaults use
 * AiTagPolicyService::getDefaultSettings() and config `ai.tenant_ai_tag_defaults`
 * (e.g. AI_TAGS_DEFAULT_AUTO_APPLY). `ai_best_practices_limit` is set to
 * min(plan max_tags_per_asset, initial_best_practices_auto_apply_cap).
 */
class TenantAiTagSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $service = app(AiTagPolicyService::class);
        $planService = app(PlanService::class);
        $cap = (int) config('ai.tenant_ai_tag_defaults.initial_best_practices_auto_apply_cap', 5);

        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            // Check if settings already exist for this tenant
            $existing = DB::table('tenant_ai_tag_settings')
                ->where('tenant_id', $tenant->id)
                ->first();

            if (!$existing) {
                $defaultSettings = $service->getDefaultSettings();
                $planMax = $planService->getMaxTagsPerAsset($tenant);
                $initialBest = max(1, min($planMax, $cap));

                // Insert default settings for this tenant
                DB::table('tenant_ai_tag_settings')->insert([
                    'tenant_id' => $tenant->id,
                    'disable_ai_tagging' => $defaultSettings['disable_ai_tagging'],
                    'enable_ai_tag_suggestions' => $defaultSettings['enable_ai_tag_suggestions'],
                    'enable_ai_tag_auto_apply' => $defaultSettings['enable_ai_tag_auto_apply'],
                    'ai_auto_tag_limit_mode' => $defaultSettings['ai_auto_tag_limit_mode'],
                    'ai_auto_tag_limit_value' => $defaultSettings['ai_auto_tag_limit_value'],
                    'ai_best_practices_limit' => $initialBest,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
