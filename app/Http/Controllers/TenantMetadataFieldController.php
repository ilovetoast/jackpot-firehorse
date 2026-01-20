<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantMetadataFieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Tenant Metadata Field Controller
 *
 * Phase C3: Admin endpoints for managing tenant-scoped custom metadata fields.
 *
 * Authorization:
 * - All methods require metadata.tenant.field.create or metadata.tenant.field.manage permission
 */
class TenantMetadataFieldController extends Controller
{
    public function __construct(
        protected TenantMetadataFieldService $fieldService
    ) {
    }

    /**
     * List all tenant metadata fields.
     *
     * GET /tenant/metadata/fields
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.field.manage')) {
            abort(403, 'You do not have permission to view tenant metadata fields.');
        }

        $includeInactive = $request->boolean('include_inactive', false);
        $fields = $this->fieldService->listFieldsByTenant($tenant, $includeInactive);

        return response()->json([
            'fields' => $fields,
        ]);
    }

    /**
     * Create a new tenant metadata field.
     *
     * POST /tenant/metadata/fields
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.field.create')) {
            abort(403, 'You do not have permission to create tenant metadata fields.');
        }

        // Validate request
        $validated = $request->validate([
            'key' => 'required|string|max:255',
            'system_label' => 'required|string|max:255',
            'type' => 'required|string|in:text,textarea,select,multiselect,number,boolean,date',
            'applies_to' => 'required|string|in:image,video,document,all',
            'options' => 'nullable|array',
            'options.*.value' => 'required|string',
            'options.*.label' => 'required|string',
            'is_filterable' => 'nullable|boolean',
            'show_on_upload' => 'nullable|boolean',
            'show_on_edit' => 'nullable|boolean',
            'show_in_filters' => 'nullable|boolean',
            'group_key' => 'nullable|string|max:255',
        ]);

        try {
            $fieldId = $this->fieldService->createField($tenant, $validated);

            return response()->json([
                'success' => true,
                'field_id' => $fieldId,
                'message' => 'Metadata field created successfully',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to create tenant metadata field', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Disable a tenant metadata field.
     *
     * POST /tenant/metadata/fields/{field}/disable
     *
     * @param int $field
     * @return JsonResponse
     */
    public function disable(int $field): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.field.manage')) {
            abort(403, 'You do not have permission to manage tenant metadata fields.');
        }

        try {
            $this->fieldService->disableField($tenant, $field);

            return response()->json([
                'success' => true,
                'message' => 'Metadata field disabled successfully',
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to disable tenant metadata field', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'field_id' => $field,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to disable field',
            ], 500);
        }
    }

    /**
     * Enable a tenant metadata field.
     *
     * POST /tenant/metadata/fields/{field}/enable
     *
     * @param int $field
     * @return JsonResponse
     */
    public function enable(int $field): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.tenant.field.manage')) {
            abort(403, 'You do not have permission to manage tenant metadata fields.');
        }

        try {
            $this->fieldService->enableField($tenant, $field);

            return response()->json([
                'success' => true,
                'message' => 'Metadata field enabled successfully',
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to enable tenant metadata field', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'field_id' => $field,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to enable field',
            ], 500);
        }
    }
}
