<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI Tag Policy Service
 *
 * Phase J.2.2: Tenant-level AI tagging controls
 * 
 * Provides deterministic policy evaluation for AI tagging behaviors without
 * modifying the core AI generation pipeline. All decisions are based on
 * tenant-specific settings with safe defaults that preserve existing behavior.
 */
class AiTagPolicyService
{
    /**
     * Cache TTL for tenant settings (5 minutes)
     */
    public const CACHE_TTL = 300;

    /**
     * Best practices limit for auto-applied tags per asset
     */
    public const BEST_PRACTICES_AUTO_TAG_LIMIT = 5;

    /**
     * Check if AI tagging is allowed for a tenant (master toggle).
     *
     * @param Tenant $tenant The tenant to check
     * @return bool True if AI tagging is allowed (not disabled)
     */
    public function isAiTaggingEnabled(Tenant $tenant): bool
    {
        $settings = $this->getTenantSettings($tenant);
        return !($settings['disable_ai_tagging'] ?? false);
    }

    /**
     * Check if AI tag suggestions should be shown to users.
     *
     * @param Tenant $tenant The tenant to check
     * @return bool True if AI tag suggestions should be shown
     */
    public function areAiTagSuggestionsEnabled(Tenant $tenant): bool
    {
        $settings = $this->getTenantSettings($tenant);
        return $settings['enable_ai_tag_suggestions'] ?? true; // Default true
    }

    /**
     * Check if AI tags should be auto-applied (without user intervention).
     *
     * @param Tenant $tenant The tenant to check
     * @return bool True if auto-apply is enabled (OFF by default per requirement)
     */
    public function isAiTagAutoApplyEnabled(Tenant $tenant): bool
    {
        $settings = $this->getTenantSettings($tenant);
        return $settings['enable_ai_tag_auto_apply'] ?? false; // Default false (OFF by default)
    }

    /**
     * Get the auto-apply tag limit for an asset.
     *
     * @param Tenant $tenant The tenant to check
     * @return int Maximum number of tags to auto-apply per asset
     */
    public function getAutoApplyTagLimit(Tenant $tenant): int
    {
        $settings = $this->getTenantSettings($tenant);
        
        $mode = $settings['ai_auto_tag_limit_mode'] ?? 'best_practices';
        
        if ($mode === 'custom') {
            return max(1, min(10, $settings['ai_auto_tag_limit_value'] ?? self::BEST_PRACTICES_AUTO_TAG_LIMIT));
        }
        
        // best_practices mode - use tenant-configurable limit (default 5, max 10)
        return max(1, min(10, $settings['ai_best_practices_limit'] ?? self::BEST_PRACTICES_AUTO_TAG_LIMIT));
    }

    /**
     * Determine if AI tagging should proceed for an asset.
     * 
     * This is the main entry point guard that should be called before any AI work.
     *
     * @param Asset $asset The asset to check
     * @return array{should_proceed: bool, reason?: string}
     */
    public function shouldProceedWithAiTagging(Asset $asset): array
    {
        $tenant = Tenant::find($asset->tenant_id);
        
        if (!$tenant) {
            return [
                'should_proceed' => false,
                'reason' => 'tenant_not_found',
            ];
        }

        // Master toggle check (hard stop)
        if (!$this->isAiTaggingEnabled($tenant)) {
            return [
                'should_proceed' => false,
                'reason' => 'ai_tagging_disabled',
            ];
        }

        return ['should_proceed' => true];
    }

    /**
     * Determine which AI tags should be auto-applied from candidates.
     *
     * @param Asset $asset The asset to check
     * @param array $candidates Array of tag candidates with confidence scores
     * @return array Tags that should be auto-applied
     */
    public function selectTagsForAutoApply(Asset $asset, array $candidates): array
    {
        $tenant = Tenant::find($asset->tenant_id);
        
        if (!$tenant || !$this->isAiTagAutoApplyEnabled($tenant)) {
            return []; // No auto-apply
        }

        $limit = $this->getAutoApplyTagLimit($tenant);
        
        // Sort by confidence (highest first) and take up to limit
        usort($candidates, function ($a, $b) {
            $confA = $a['confidence'] ?? 0;
            $confB = $b['confidence'] ?? 0;
            return $confB <=> $confA; // Descending order
        });

        return array_slice($candidates, 0, $limit);
    }

    /**
     * Get comprehensive policy status for an asset (for admin UI/debugging).
     *
     * @param Asset $asset The asset to check
     * @return array Comprehensive policy evaluation
     */
    public function getPolicyStatus(Asset $asset): array
    {
        $tenant = Tenant::find($asset->tenant_id);
        
        if (!$tenant) {
            return [
                'tenant_found' => false,
                'ai_tagging_enabled' => false,
                'ai_suggestions_enabled' => false,
                'ai_auto_apply_enabled' => false,
                'auto_apply_limit' => 0,
                'should_proceed' => false,
                'reason' => 'tenant_not_found',
            ];
        }

        $settings = $this->getTenantSettings($tenant);
        $shouldProceed = $this->shouldProceedWithAiTagging($asset);

        return [
            'tenant_found' => true,
            'tenant_id' => $tenant->id,
            'ai_tagging_enabled' => $this->isAiTaggingEnabled($tenant),
            'ai_suggestions_enabled' => $this->areAiTagSuggestionsEnabled($tenant),
            'ai_auto_apply_enabled' => $this->isAiTagAutoApplyEnabled($tenant),
            'auto_apply_limit' => $this->getAutoApplyTagLimit($tenant),
            'auto_apply_limit_mode' => $settings['ai_auto_tag_limit_mode'] ?? 'best_practices',
            'should_proceed' => $shouldProceed['should_proceed'],
            'reason' => $shouldProceed['reason'] ?? null,
            'settings' => $settings,
        ];
    }

    /**
     * Create or update tenant AI tag settings.
     *
     * @param Tenant $tenant The tenant to update
     * @param array $settings Settings to update
     * @return array Updated settings with all defaults applied
     */
    public function updateTenantSettings(Tenant $tenant, array $settings): array
    {
        $allowedKeys = [
            'disable_ai_tagging',
            'enable_ai_tag_suggestions',
            'enable_ai_tag_auto_apply',
            'ai_auto_tag_limit_mode',
            'ai_auto_tag_limit_value',
            'ai_best_practices_limit',
        ];

        // Filter to only allowed keys
        $filteredSettings = array_intersect_key($settings, array_flip($allowedKeys));
        
        // Validation
        if (isset($filteredSettings['ai_auto_tag_limit_mode'])) {
            if (!in_array($filteredSettings['ai_auto_tag_limit_mode'], ['best_practices', 'custom'])) {
                throw new \InvalidArgumentException('Invalid ai_auto_tag_limit_mode. Must be "best_practices" or "custom".');
            }
        }

        if (isset($filteredSettings['ai_auto_tag_limit_value'])) {
            if (!is_null($filteredSettings['ai_auto_tag_limit_value']) && 
                ($filteredSettings['ai_auto_tag_limit_value'] < 1 || $filteredSettings['ai_auto_tag_limit_value'] > 10)) {
                throw new \InvalidArgumentException('ai_auto_tag_limit_value must be between 1 and 10 or null.');
            }
        }
        
        if (isset($filteredSettings['ai_best_practices_limit'])) {
            if ($filteredSettings['ai_best_practices_limit'] < 1 || $filteredSettings['ai_best_practices_limit'] > 10) {
                throw new \InvalidArgumentException('ai_best_practices_limit must be between 1 and 10.');
            }
        }

        // Upsert settings
        // Check if ai_best_practices_limit column exists (added in later migration)
        $hasBestPracticesLimit = Schema::hasColumn('tenant_ai_tag_settings', 'ai_best_practices_limit');
        
        // Remove ai_best_practices_limit from update if column doesn't exist
        $settingsToUpdate = $filteredSettings;
        if (!$hasBestPracticesLimit && isset($settingsToUpdate['ai_best_practices_limit'])) {
            unset($settingsToUpdate['ai_best_practices_limit']);
        }
        
        // Use updateOrInsert - it only sets created_at on insert, not on update
        $exists = DB::table('tenant_ai_tag_settings')
            ->where('tenant_id', $tenant->id)
            ->exists();
        
        if ($exists) {
            // Update existing record (don't touch created_at)
            if (!empty($settingsToUpdate)) {
                DB::table('tenant_ai_tag_settings')
                    ->where('tenant_id', $tenant->id)
                    ->update(array_merge($settingsToUpdate, [
                        'updated_at' => now(),
                    ]));
            }
        } else {
            // Insert new record (set both timestamps)
            DB::table('tenant_ai_tag_settings')
                ->insert(array_merge($settingsToUpdate, [
                    'tenant_id' => $tenant->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
        }

        // Clear cache
        $this->clearCache($tenant);
        
        // Return updated settings
        return $this->getTenantSettings($tenant);
    }

    /**
     * Get tenant AI tag settings (cached).
     *
     * @param Tenant $tenant The tenant to get settings for
     * @return array Tenant settings with defaults
     */
    public function getTenantSettings(Tenant $tenant): array
    {
        $cacheKey = "tenant_ai_tag_settings:{$tenant->id}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenant) {
            $settings = DB::table('tenant_ai_tag_settings')
                ->where('tenant_id', $tenant->id)
                ->first();

            if (!$settings) {
                // Return safe defaults that preserve existing behavior
                return [
                    'disable_ai_tagging' => false,
                    'enable_ai_tag_suggestions' => true,
                    'enable_ai_tag_auto_apply' => false, // OFF by default per requirement
                    'ai_auto_tag_limit_mode' => 'best_practices',
                    'ai_auto_tag_limit_value' => null,
                ];
            }

            // Check if ai_best_practices_limit column exists (added in later migration)
            $hasBestPracticesLimit = Schema::hasColumn('tenant_ai_tag_settings', 'ai_best_practices_limit');
            
            $result = [
                'disable_ai_tagging' => (bool) $settings->disable_ai_tagging,
                'enable_ai_tag_suggestions' => (bool) $settings->enable_ai_tag_suggestions,
                'enable_ai_tag_auto_apply' => (bool) $settings->enable_ai_tag_auto_apply,
                'ai_auto_tag_limit_mode' => $settings->ai_auto_tag_limit_mode,
                'ai_auto_tag_limit_value' => $settings->ai_auto_tag_limit_value,
            ];
            
            // Only include ai_best_practices_limit if column exists
            if ($hasBestPracticesLimit) {
                $result['ai_best_practices_limit'] = $settings->ai_best_practices_limit ?? self::BEST_PRACTICES_AUTO_TAG_LIMIT;
            } else {
                $result['ai_best_practices_limit'] = self::BEST_PRACTICES_AUTO_TAG_LIMIT; // Use default if column doesn't exist
            }
            
            return $result;
        });
    }

    /**
     * Clear cached settings for a tenant.
     *
     * @param Tenant $tenant The tenant to clear cache for
     * @return void
     */
    public function clearCache(Tenant $tenant): void
    {
        Cache::forget("tenant_ai_tag_settings:{$tenant->id}");
    }

    /**
     * Get the recommended settings for a new tenant (safe defaults).
     *
     * @return array Default settings that preserve existing behavior
     */
    public function getDefaultSettings(): array
    {
        return [
            'disable_ai_tagging' => false,           // AI enabled by default
            'enable_ai_tag_suggestions' => true,    // Suggestions enabled by default
            'enable_ai_tag_auto_apply' => false,    // Auto-apply OFF by default per requirement
            'ai_auto_tag_limit_mode' => 'best_practices',
            'ai_auto_tag_limit_value' => null,
        ];
    }

    /**
     * Bulk check AI tagging status for multiple tenants (for admin reporting).
     *
     * @param array $tenantIds Array of tenant IDs to check
     * @return array Keyed by tenant_id with policy status
     */
    public function bulkGetTenantStatus(array $tenantIds): array
    {
        $results = [];
        
        // Batch fetch all settings to minimize database queries
        $allSettings = DB::table('tenant_ai_tag_settings')
            ->whereIn('tenant_id', $tenantIds)
            ->get()
            ->keyBy('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $settings = $allSettings->get($tenantId);
            
            if (!$settings) {
                $results[$tenantId] = $this->getDefaultSettings();
            } else {
                $results[$tenantId] = [
                    'disable_ai_tagging' => (bool) $settings->disable_ai_tagging,
                    'enable_ai_tag_suggestions' => (bool) $settings->enable_ai_tag_suggestions,
                    'enable_ai_tag_auto_apply' => (bool) $settings->enable_ai_tag_auto_apply,
                    'ai_auto_tag_limit_mode' => $settings->ai_auto_tag_limit_mode,
                    'ai_auto_tag_limit_value' => $settings->ai_auto_tag_limit_value,
                ];
            }
        }

        return $results;
    }
}