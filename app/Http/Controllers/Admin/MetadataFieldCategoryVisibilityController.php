<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemMetadataVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Metadata Field Category Visibility Controller
 *
 * Phase C1, Step 2: Admin-only endpoints for managing system-level
 * category suppression of metadata fields.
 *
 * Authorization:
 * - All methods require metadata.system.visibility.manage permission
 */
class MetadataFieldCategoryVisibilityController extends Controller
{
    public function __construct(
        protected SystemMetadataVisibilityService $visibilityService
    ) {
    }

    /**
     * Get category visibility rules for a metadata field.
     *
     * GET /admin/metadata/fields/{field}/categories
     *
     * @param int $field Metadata field ID
     * @return JsonResponse
     */
    public function getCategories(int $field): JsonResponse
    {
        if (!Auth::user()->can('metadata.system.visibility.manage')) {
            abort(403);
        }

        // Verify field exists and is system-scoped
        $fieldRecord = DB::table('metadata_fields')
            ->where('id', $field)
            ->where('scope', 'system')
            ->first();

        if (!$fieldRecord) {
            return response()->json([
                'error' => 'Field not found or is not a system field',
            ], 404);
        }

        $categories = $this->visibilityService->getFieldCategories($field);

        return response()->json([
            'field_id' => $field,
            'field_key' => $fieldRecord->key,
            'field_label' => $fieldRecord->system_label,
            'categories' => $categories,
        ]);
    }

    /**
     * Suppress a field for a system category.
     *
     * POST /admin/metadata/fields/{field}/categories/{category}/suppress
     *
     * @param int $field Metadata field ID
     * @param int $category System category ID
     * @return JsonResponse
     */
    public function suppress(int $field, int $category): JsonResponse
    {
        if (!Auth::user()->can('metadata.system.visibility.manage')) {
            abort(403);
        }

        try {
            $this->visibilityService->suppressForCategory($field, $category);

            // Audit log
            Log::info('Metadata field category suppression added', [
                'user_id' => Auth::id(),
                'metadata_field_id' => $field,
                'system_category_id' => $category,
                'action' => 'suppress',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Field suppressed for category',
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Failed to suppress field for category', [
                'user_id' => Auth::id(),
                'metadata_field_id' => $field,
                'system_category_id' => $category,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to suppress field',
            ], 500);
        }
    }

    /**
     * Unsuppress a field for a system category.
     *
     * DELETE /admin/metadata/fields/{field}/categories/{category}/suppress
     *
     * @param int $field Metadata field ID
     * @param int $category System category ID
     * @return JsonResponse
     */
    public function unsuppress(int $field, int $category): JsonResponse
    {
        if (!Auth::user()->can('metadata.system.visibility.manage')) {
            abort(403);
        }

        try {
            $this->visibilityService->unsuppressForCategory($field, $category);

            // Audit log
            Log::info('Metadata field category suppression removed', [
                'user_id' => Auth::id(),
                'metadata_field_id' => $field,
                'system_category_id' => $category,
                'action' => 'unsuppress',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Field unsuppressed for category',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to unsuppress field for category', [
                'user_id' => Auth::id(),
                'metadata_field_id' => $field,
                'system_category_id' => $category,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to unsuppress field',
            ], 500);
        }
    }
}
