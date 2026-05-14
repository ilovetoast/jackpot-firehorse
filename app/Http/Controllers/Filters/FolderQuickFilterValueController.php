<?php

namespace App\Http\Controllers\Filters;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MetadataField;
use App\Services\Filters\FolderQuickFilterAssignmentService;
use App\Services\Filters\FolderQuickFilterEligibilityService;
use App\Services\Filters\FolderQuickFilterValueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 4 — value picker endpoint for the sidebar quick-filter flyout.
 *
 *   GET /api/tenant/folders/{category}/quick-filters/{field}/values
 *
 * The endpoint is intentionally ONE round trip per opened flyout: the sidebar
 * never preloads values for unopened quick filters (Phase 4 lazy-load rule).
 *
 * Authorization layers (applied in order):
 *   1. Tenant context exists.
 *   2. Authenticated user belongs to the tenant.
 *   3. Folder quick filters feature is globally enabled.
 *   4. Category belongs to the tenant.
 *   5. Field exists and (for tenant-scoped fields) belongs to the tenant.
 *   6. Field is eligible (FolderQuickFilterEligibilityService).
 *   7. Field is currently enabled as a quick filter for *this* folder
 *      (FolderQuickFilterAssignmentService::isQuickFilterEnabled).
 *
 * Each gate returns a distinct error so admins can diagnose misconfigurations
 * without leaking the existence of resources outside their tenant.
 */
class FolderQuickFilterValueController extends Controller
{
    public function __construct(
        protected FolderQuickFilterAssignmentService $assignment,
        protected FolderQuickFilterEligibilityService $eligibility,
        protected FolderQuickFilterValueService $values,
    ) {}

    public function show(Request $request, int $category, int $field): JsonResponse
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        if (! $user->belongsToTenant($tenant->id)) {
            return response()->json(['error' => 'User does not belong to this tenant'], 403);
        }

        // Feature flag — same surface that gates the sidebar payload itself.
        if (! (bool) config('categories.folder_quick_filters.enabled', false)) {
            return response()->json(['error' => 'feature_disabled'], 422);
        }

        $categoryModel = Category::query()
            ->where('id', $category)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $categoryModel) {
            return response()->json(['error' => 'Category not found or does not belong to tenant'], 404);
        }

        $fieldModel = MetadataField::query()->where('id', $field)->first();
        if (! $fieldModel) {
            return response()->json(['error' => 'Field not found'], 404);
        }
        // Same tenant-scoping rule used by FolderQuickFilterController::update.
        if (
            ! $fieldModel->is_internal_only
            && ($fieldModel->scope ?? null) === 'tenant'
            && (int) ($fieldModel->tenant_id ?? 0) !== (int) $tenant->id
        ) {
            return response()->json(['error' => 'Field does not belong to this tenant'], 403);
        }

        // Eligibility gate. We send the same admin-facing reason the assignment
        // controller sends — the UI maps this to a non-breaking error message.
        if (! $this->eligibility->isEligible($fieldModel)) {
            $reason = $this->eligibility->reasonIneligible($fieldModel);

            return response()->json([
                'error' => 'ineligible_filter',
                'reason_code' => $reason,
                'message' => $this->eligibility->explainReason($reason),
            ], 422);
        }

        // Assignment gate — refuse to surface values for filters that are not
        // currently enabled as quick filters for this folder. Without this
        // gate the endpoint would be a generic "any field's options" reader.
        if (! $this->assignment->isQuickFilterEnabled($categoryModel, $fieldModel)) {
            return response()->json([
                'error' => 'not_enabled_for_folder',
                'message' => 'This filter is not enabled as a quick filter for this folder.',
            ], 422);
        }

        $activeFilters = $this->parseActiveFilters($request);
        $payload = $this->values->getValues($categoryModel, $fieldModel, $activeFilters);

        return response()->json($payload);
    }

    /**
     * Phase 5 — parse the page's active filter state out of the request so
     * the value service can forward it to the facet count provider.
     *
     * Accepted shapes (in priority order):
     *   1. `?filters=<json>` — same JSON shape used elsewhere in this app
     *      (`{ field_key: { operator, value } }`). Sent by the flyout.
     *   2. `?activeFilters=<json>` — alias for clarity.
     *
     * Returns `null` when nothing is provided so the provider can short-circuit
     * the "no filters to exclude" branch cheaply. We intentionally do NOT
     * fall back to flat query params (e.g. `?subject_type[]=people`) here:
     * the flyout already speaks the canonical JSON shape and accepting two
     * formats invites drift between this endpoint and AssetController::index.
     *
     * @return array<string, array{operator: string, value: mixed}>|null
     */
    private function parseActiveFilters(Request $request): ?array
    {
        $raw = $request->input('filters');
        if ($raw === null || $raw === '') {
            $raw = $request->input('activeFilters');
        }
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $this->normalizeActiveFilters($raw);
        }
        if (! is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $this->normalizeActiveFilters($decoded);
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, array{operator: string, value: mixed}>|null
     */
    private function normalizeActiveFilters(array $raw): ?array
    {
        $out = [];
        foreach ($raw as $key => $def) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (! is_array($def) || ! array_key_exists('value', $def)) {
                continue;
            }
            $value = $def['value'];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $out[$key] = [
                'operator' => is_string($def['operator'] ?? null) ? $def['operator'] : 'equals',
                'value' => $value,
            ];
        }

        return $out === [] ? null : $out;
    }
}
