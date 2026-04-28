<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\Tenant;
use App\Services\AiUsageService;
use App\Studio\LayerExtraction\Contracts\SamSegmentationClientInterface;
use App\Services\Fal\FalModelPricingService;
use Illuminate\Support\Facades\App;

/**
 * User-selectable extraction: local (floodfill) vs AI (Sam + Fal), not auto-selected from FAL_KEY alone.
 */
final class StudioLayerExtractionMethodService
{
    public function __construct(
        protected FalModelPricingService $falModelPricing,
    ) {}

    public const METHOD_LOCAL = 'local';

    public const METHOD_AI = 'ai';

    /**
     * @return self::METHOD_LOCAL|self::METHOD_AI
     */
    public function resolvedMethodForRequest(?string $raw, Tenant $tenant, Brand $brand): string
    {
        if ($raw === null || $raw === '') {
            $d = (string) config('studio_layer_extraction.default_extraction_method', 'local');
            if (! in_array($d, [self::METHOD_LOCAL, self::METHOD_AI], true)) {
                $d = self::METHOD_LOCAL;
            }
            if ($d === self::METHOD_AI && $this->isAiExtractionRuntimeAvailable($tenant, $brand)) {
                return self::METHOD_AI;
            }

            return self::METHOD_LOCAL;
        }
        $m = strtolower(trim($raw));
        if ($m === self::METHOD_AI) {
            return self::METHOD_AI;
        }

        return self::METHOD_LOCAL;
    }

    /**
     * App config + tenant/brand + SAM + remote client (Fal, etc.).
     */
    public function isAiExtractionRuntimeAvailable(Tenant $tenant, Brand $brand): bool
    {
        if (! (bool) config('studio_layer_extraction.allow_ai', true)) {
            return false;
        }
        if (! (bool) config('studio_layer_extraction.sam.enabled', false)) {
            return false;
        }
        if (! $this->tenantAllowsAiExtraction($tenant)) {
            return false;
        }
        if (! $this->brandAllowsAiExtraction($brand)) {
            return false;
        }

        return $this->samClient()->isAvailable();
    }

    public function isBillableOnSuccess(string $method, Tenant $tenant, Brand $brand): bool
    {
        if ($method === self::METHOD_LOCAL) {
            return (bool) config('studio_layer_extraction.bill_floodfill_extraction', false);
        }

        return $this->isAiExtractionRuntimeAvailable($tenant, $brand);
    }

    public function buildAvailableMethods(Tenant $tenant, Brand $brand, AiUsageService $aiUsage): array
    {
        $creditKey = 'studio_layer_extraction';
        $credits = $aiUsage->getCreditWeight($creditKey);
        $estimatedUsd = $this->falModelPricing->estimatedCostUsd();
        $usdSource = $this->falModelPricing->costSource();
        $aiRuntime = $this->isAiExtractionRuntimeAvailable($tenant, $brand);
        $aiUnavailReason = $aiRuntime
            ? null
            : $this->aiUnavailableReason($tenant, $brand);

        return [
            [
                'key' => self::METHOD_LOCAL,
                'label' => 'Local mask detection',
                'description' => 'Local mask detection. Free, fast, best for simple cutouts (unless BILL_FLOODFILL is on).',
                'billable' => (bool) config('studio_layer_extraction.bill_floodfill_extraction', false),
                'available' => true,
            ],
            [
                'key' => self::METHOD_AI,
                'label' => 'AI segmentation',
                'description' => 'AI segmentation. Better object masks, uses credits (Fal/SAM when available).',
                'billable' => true,
                'available' => $aiRuntime,
                'unavailable_reason' => $aiUnavailReason,
                'credit_key' => $creditKey,
                'estimated_credits' => $credits,
                'estimated_provider_cost_usd' => $estimatedUsd,
                'provider_cost_source' => $usdSource,
            ],
        ];
    }

    public function defaultMethodForContext(Tenant $tenant, Brand $brand): string
    {
        $d = (string) config('studio_layer_extraction.default_extraction_method', 'local');
        if ($d === self::METHOD_AI && $this->isAiExtractionRuntimeAvailable($tenant, $brand)) {
            return self::METHOD_AI;
        }

        return self::METHOD_LOCAL;
    }

    private function aiUnavailableReason(Tenant $tenant, Brand $brand): string
    {
        if (! (bool) config('studio_layer_extraction.allow_ai', true)) {
            return 'AI layer extraction is disabled in this environment.';
        }
        if (! (bool) config('studio_layer_extraction.sam.enabled', false)) {
            return 'AI segmentation is not enabled.';
        }
        if (! $this->tenantAllowsAiExtraction($tenant)) {
            return 'AI layer extraction is not allowed for this workspace.';
        }
        if (! $this->brandAllowsAiExtraction($brand)) {
            return 'AI layer extraction is not allowed for this brand.';
        }

        return 'AI segmentation is not available.';
    }

    public function tenantAllowsAiExtraction(Tenant $tenant): bool
    {
        $s = $tenant->settings ?? [];
        if (is_array($s) && array_key_exists('studio_layer_extraction_allow_ai', $s)) {
            return (bool) $s['studio_layer_extraction_allow_ai'];
        }

        return true;
    }

    public function brandAllowsAiExtraction(Brand $brand): bool
    {
        $s = $brand->settings ?? [];
        if (is_array($s) && array_key_exists('studio_layer_extraction_allow_ai', $s)) {
            return (bool) $s['studio_layer_extraction_allow_ai'];
        }

        return true;
    }

    private function samClient(): SamSegmentationClientInterface
    {
        return App::make(SamSegmentationClientInterface::class);
    }
}
