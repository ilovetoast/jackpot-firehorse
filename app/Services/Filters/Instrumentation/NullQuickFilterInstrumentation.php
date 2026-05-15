<?php

namespace App\Services\Filters\Instrumentation;

use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;
use App\Services\Filters\Contracts\QuickFilterInstrumentation;

/**
 * Phase 5.2 default {@see QuickFilterInstrumentation} implementation.
 *
 * Swallows every event. Bound as the default in AppServiceProvider so
 * production deploys can iterate the call sites without having to wire up
 * a real analytics sink. Phase 6+ swaps this for an event-bus / Mixpanel /
 * Snowflake / Sentry-breadcrumb / etc. implementation without any caller
 * changing.
 */
class NullQuickFilterInstrumentation implements QuickFilterInstrumentation
{
    public function recordOpen(
        MetadataField $field,
        Category $folder,
        ?Tenant $tenant = null,
    ): void {
        // intentional no-op
    }

    public function recordOverflowOpen(
        Category $folder,
        ?Tenant $tenant = null,
    ): void {
        // intentional no-op
    }

    public function recordSelection(
        MetadataField $field,
        Category $folder,
        mixed $value,
        ?Tenant $tenant = null,
    ): void {
        // intentional no-op
    }
}
