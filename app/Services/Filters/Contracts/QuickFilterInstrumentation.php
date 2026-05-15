<?php

namespace App\Services\Filters\Contracts;

use App\Models\Category;
use App\Models\MetadataField;
use App\Models\Tenant;

/**
 * Phase 5.2 — instrumentation seam for folder quick filter interactions.
 *
 * Implementations record lightweight usage signals — flyout opens, value
 * selections, overflow opens — for future operational tooling. The seam
 * exists so the call sites never have to know whether a real analytics
 * pipeline is bound; in dev/test the {@see Null implementation} swallows
 * everything.
 *
 * Strict contract:
 *   - Implementations MUST return quickly. They are called inside the
 *     request lifecycle.
 *   - Implementations MUST NOT throw. Any error must be swallowed +
 *     logged so a broken sink never blocks the user-facing flow.
 *   - Implementations MUST be safe to call with null tenant (background /
 *     test paths).
 */
interface QuickFilterInstrumentation
{
    /**
     * Recorded when a quick filter value flyout opens.
     */
    public function recordOpen(
        MetadataField $field,
        Category $folder,
        ?Tenant $tenant = null,
    ): void;

    /**
     * Recorded when the overflow ("+N more") flyout opens.
     */
    public function recordOverflowOpen(
        Category $folder,
        ?Tenant $tenant = null,
    ): void;

    /**
     * Recorded when a value is selected/toggled inside a quick filter flyout.
     * `$value` is the raw user-selected payload (string|bool|list).
     */
    public function recordSelection(
        MetadataField $field,
        Category $folder,
        mixed $value,
        ?Tenant $tenant = null,
    ): void;
}
