<?php

namespace App\Http\Controllers\Filters;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MetadataField;
use App\Services\Filters\FolderQuickFilterAssignmentService;
use App\Services\Filters\FolderQuickFilterEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 2 — admin endpoints for folder quick filter assignment.
 *
 * Deliberately separated from the (phase-locked) TenantMetadataRegistryController
 * so this surface can iterate independently. Endpoints:
 *   PATCH  /api/tenant/metadata/fields/{field}/categories/{category}/folder-quick-filter
 *     payload: { enabled?: bool, order?: int|null, weight?: int|null }
 *
 * Permission: same as folder enablement — `metadata.tenant.visibility.manage`.
 */
class FolderQuickFilterController extends Controller
{
    public function __construct(
        protected FolderQuickFilterAssignmentService $assignment,
        protected FolderQuickFilterEligibilityService $eligibility,
    ) {}

    public function update(Request $request, int $field, int $category): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        if (! $user || ! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            return response()->json(['error' => 'You do not have permission to manage metadata visibility.'], 403);
        }

        $validated = $request->validate([
            'enabled' => 'sometimes|boolean',
            'order' => 'sometimes|nullable|integer|min:0',
            'weight' => 'sometimes|nullable|integer|min:0',
        ]);

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
        // Tenant-scoped fields must belong to this tenant.
        if (
            ! $fieldModel->is_internal_only
            && ($fieldModel->scope ?? null) === 'tenant'
            && (int) ($fieldModel->tenant_id ?? 0) !== (int) $tenant->id
        ) {
            return response()->json(['error' => 'Field does not belong to this tenant'], 403);
        }

        // Eligibility gate. Surface the same admin-facing reason the UI uses.
        if (! $this->eligibility->isEligible($fieldModel)) {
            $reason = $this->eligibility->reasonIneligible($fieldModel);

            return response()->json([
                'error' => 'ineligible_filter',
                'reason_code' => $reason,
                'message' => $this->eligibility->explainReason($reason),
            ], 422);
        }

        if (array_key_exists('enabled', $validated)) {
            if ($validated['enabled']) {
                $this->assignment->enableQuickFilter($categoryModel, $fieldModel, [
                    'order' => $validated['order'] ?? null,
                    'weight' => $validated['weight'] ?? null,
                    'source' => FolderQuickFilterAssignmentService::SOURCE_MANUAL,
                ]);
            } else {
                $this->assignment->disableQuickFilter($categoryModel, $fieldModel);
            }
        } else {
            // No enabled-flag change: at most an order/weight tweak. Both are
            // eligibility-gated by the assignment service.
            if (array_key_exists('order', $validated)) {
                $this->assignment->updateQuickFilterOrder($categoryModel, $fieldModel, $validated['order']);
            }
            if (array_key_exists('weight', $validated)) {
                $this->assignment->updateQuickFilterWeight($categoryModel, $fieldModel, $validated['weight']);
            }
        }

        return response()->json([
            'field_id' => $fieldModel->id,
            'category_id' => $categoryModel->id,
            'quick_filter' => [
                'enabled' => $this->assignment->isQuickFilterEnabled($categoryModel, $fieldModel),
                'supported' => true,
            ],
        ]);
    }
}
