<?php

namespace App\Services\Prostaff;

use App\Exceptions\CreatorModuleInactiveException;
use App\Models\Tenant;
use App\Services\CreatorModuleMessageService;
use App\Services\FeatureGate;

/**
 * Single choke point for Creator / Prostaff module entitlement (throws).
 * Boolean checks remain {@see FeatureGate::creatorModuleEnabled()}.
 */
final class EnsureCreatorModuleEnabled
{
    public function __construct(
        private FeatureGate $featureGate,
        private CreatorModuleMessageService $creatorModuleMessageService
    ) {}

    public function assertEnabled(Tenant $tenant): void
    {
        if (! $this->featureGate->creatorModuleEnabled($tenant)) {
            throw new CreatorModuleInactiveException(
                $this->creatorModuleMessageService->getExpiredMessage($tenant)
            );
        }
    }
}
