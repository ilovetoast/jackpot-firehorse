<?php

namespace App\Services\Security;

use App\Models\Tenant;
use App\Services\FileTypeService;
use App\Services\PlanService;

/**
 * Phase 6: Resolve per-plan, per-type upload size caps.
 *
 * The DAM has a global upload size cap defined in `config/file_types.php`
 * (per registered type and a fallback). Some plans should ship with a
 * smaller cap — e.g. a free plan capping video at 200 MB even though the
 * registry's global cap is 4 GB. This service is the single source of
 * truth for "what's the smallest cap that applies to this upload?"
 *
 * Design:
 *   - Plan caps live in `config('assets.plan_upload_caps.<plan>.<type>')`.
 *   - `null` for a plan = inherit registry caps (no plan override).
 *   - `null` for a plan/type pair = inherit registry cap for that type.
 *   - The effective cap is `min(registry cap, plan cap when defined)`.
 *
 * Returned shape lets callers (preflight, UI banners, error messages)
 * uniformly emit "you can upload up to X MB on your plan; this plan
 * type caps Y at Z MB":
 *
 *   [
 *     'effective_cap_bytes' => 209715200,
 *     'registry_cap_bytes' => 4294967296,
 *     'plan_cap_bytes' => 209715200,
 *     'plan' => 'free',
 *     'file_type' => 'video',
 *     'plan_overrides' => true,
 *   ]
 */
class PlanUploadCapResolver
{
    public function __construct(
        protected FileTypeService $fileTypeService,
        protected PlanService $planService,
    ) {
    }

    public function resolve(Tenant $tenant, ?string $mimeType, ?string $extension): array
    {
        $type = $this->fileTypeService->detectFileType($mimeType, $extension);
        $registryCap = $this->lookupRegistryCap($type);

        $plan = $this->resolvePlanKey($tenant);
        $planCaps = (array) config('assets.plan_upload_caps.'.$plan, []);

        $planCap = $planCaps[$type] ?? null;

        if ($planCap === null) {
            return [
                'effective_cap_bytes' => $registryCap,
                'registry_cap_bytes' => $registryCap,
                'plan_cap_bytes' => null,
                'plan' => $plan,
                'file_type' => $type,
                'plan_overrides' => false,
            ];
        }

        $effective = min($registryCap, (int) $planCap);

        return [
            'effective_cap_bytes' => $effective,
            'registry_cap_bytes' => $registryCap,
            'plan_cap_bytes' => (int) $planCap,
            'plan' => $plan,
            'file_type' => $type,
            'plan_overrides' => $effective < $registryCap,
        ];
    }

    /**
     * Per-type cap from `config/file_types.php` (upload.max_size_bytes).
     * Falls back to PHP_INT_MAX so plan caps always become effective when
     * the registry leaves a cap undefined (null).
     */
    protected function lookupRegistryCap(?string $type): int
    {
        if ($type === null) {
            return PHP_INT_MAX;
        }
        $cap = config('file_types.types.'.$type.'.upload.max_size_bytes');

        return is_int($cap) && $cap > 0 ? $cap : PHP_INT_MAX;
    }

    /**
     * Map the tenant's current plan onto a config key. Falls back to
     * 'free' when the canonical plan name is unknown.
     */
    protected function resolvePlanKey(Tenant $tenant): string
    {
        try {
            $current = $this->planService->getCanonicalPlan($tenant);
        } catch (\Throwable $e) {
            return 'free';
        }

        return match (strtolower((string) $current)) {
            'free', 'starter' => 'free',
            'pro', 'team', 'business' => 'pro',
            'enterprise', 'enterprise_plus' => 'enterprise',
            default => 'free',
        };
    }
}
