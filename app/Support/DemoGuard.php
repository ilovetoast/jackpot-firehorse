<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Services\Demo\DemoTenantService;
use Illuminate\Validation\ValidationException;

/**
 * Thin entry point for demo workspace guardrails (delegates to {@see DemoTenantService}).
 */
final class DemoGuard
{
    public static function isDemoTenant(?Tenant $tenant): bool
    {
        return app(DemoTenantService::class)->isDemoTenant($tenant);
    }

    /**
     * @throws ValidationException
     */
    public static function assertDemoCanPerform(string $action, ?Tenant $tenant): void
    {
        app(DemoTenantService::class)->assertDemoCanPerform($action, $tenant);
    }
}
