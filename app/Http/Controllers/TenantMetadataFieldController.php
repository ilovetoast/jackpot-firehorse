<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantMetadataFieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
     * Check if user can manage metadata fields (owners/admins bypass permission check)
     */
    private function canManageFields($user, $tenant, string $permission = 'metadata.tenant.field.manage'): bool
    {
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
        
        return $isTenantOwnerOrAdmin || $user->hasPermissionForTenant($tenant, $permission);
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

        // Check permission - owners and admins have full access
        if (!$this->canManageFields($user, $tenant)) {
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

        // Check permission - owners, admins, or metadata.tenant.field.manage/create
        if (!$this->canManageFields($user, $tenant, 'metadata.tenant.field.manage') && !$this->canManageFields($user, $tenant, 'metadata.tenant.field.create')) {
            abort(403, 'You do not have permission to create tenant metadata fields.');
        }

        // Validate request
        $validated = $request->validate([
            'key' => 'required|string|max:255',
            'system_label' => 'required|string|max:255',
            'type' => 'required|string|in:text,textarea,select,multiselect,number,boolean,date',
            'selectedCategories' => 'required|array|min:1',
            'selectedCategories.*' => 'required|integer|exists:categories,id',
            'options' => 'nullable|array',
            'options.*.value' => 'required|string',
            'options.*.label' => 'required|string',
            'ai_eligible' => 'nullable|boolean', // Enable AI suggestions (only for select/multiselect with options)
            'is_filterable' => 'nullable|boolean',
            'show_on_upload' => 'nullable|boolean',
            'show_on_edit' => 'nullable|boolean',
            'show_in_filters' => 'nullable|boolean',
            'group_key' => 'nullable|string|max:255',
        ]);

        try {
            $fieldId = $this->fieldService->createField($tenant, $validated);

            if ($request->header('X-Inertia')) {
                return back()->with('success', 'Metadata field created successfully.');
            }

            return response()->json([
                'success' => true,
                'field_id' => $fieldId,
                'message' => 'Metadata field created successfully',
            ], 201);
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            if ($request->header('X-Inertia')) {
                throw ValidationException::withMessages([
                    'error' => [$e->getMessage()],
                ]);
            }
            return response()->json([
                'error' => $e->getMessage(),
                'limit_type' => $e->limitType,
                'current_count' => $e->currentCount,
                'max_allowed' => $e->maxAllowed,
            ], 403);
        } catch (ValidationException $e) {
            if ($request->header('X-Inertia')) {
                throw $e;
            }
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

            if ($request->header('X-Inertia')) {
                throw ValidationException::withMessages([
                    'error' => [$e->getMessage()],
                ]);
            }
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Update a tenant metadata field.
     *
     * PUT /tenant/metadata/fields/{field}
     *
     * @param Request $request
     * @param int $field
     * @return JsonResponse
     */
    public function update(Request $request, int $field): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission - owners and admins have full access
        if (!$this->canManageFields($user, $tenant)) {
            abort(403, 'You do not have permission to manage tenant metadata fields.');
        }

        // Validate request
        $validated = $request->validate([
            'system_label' => 'sometimes|string|max:255',
            'applies_to' => 'sometimes|string|in:image,video,document,all',
            'options' => 'nullable|array',
            'options.*.value' => 'required|string',
            'options.*.label' => 'required|string',
            'ai_eligible' => 'nullable|boolean',
            'is_filterable' => 'nullable|boolean',
            'show_on_upload' => 'nullable|boolean',
            'show_on_edit' => 'nullable|boolean',
            'show_in_filters' => 'nullable|boolean',
            'group_key' => 'nullable|string|max:255',
        ]);

        try {
            $this->fieldService->updateField($tenant, $field, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Metadata field updated successfully',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to update tenant metadata field', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'field_id' => $field,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get a single tenant metadata field.
     *
     * GET /tenant/metadata/fields/{field}
     *
     * @param int $field
     * @return JsonResponse
     */
    public function show(int $field): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission - owners and admins have full access
        if (!$this->canManageFields($user, $tenant)) {
            abort(403, 'You do not have permission to view tenant metadata fields.');
        }

        // Check if it's a system field or tenant field
        $fieldRecord = DB::table('metadata_fields')
            ->where('id', $field)
            ->first();

        if (!$fieldRecord) {
            return response()->json(['error' => 'Field not found'], 404);
        }

        // If it's a system field, return it with options and ai_eligible
        if (($fieldRecord->scope ?? null) === 'system') {
            $options = DB::table('metadata_options')
                ->where('metadata_field_id', $field)
                ->select('id', 'value', 'system_label as label')
                ->orderBy('system_label')
                ->get()
                ->map(fn($opt) => [
                    'value' => $opt->value,
                    'label' => $opt->label,
                ])
                ->toArray();

            return response()->json([
                'field' => [
                    'id' => $fieldRecord->id,
                    'key' => $fieldRecord->key,
                    'system_label' => $fieldRecord->system_label,
                    'label' => $fieldRecord->system_label,
                    'type' => $fieldRecord->type,
                    'field_type' => $fieldRecord->type,
                    'scope' => 'system',
                    'is_system' => true,
                    'options' => $options,
                    'allowed_values' => $options,
                    'ai_eligible' => (bool) ($fieldRecord->ai_eligible ?? false),
                    'is_filterable' => (bool) ($fieldRecord->is_filterable ?? true),
                    'show_on_upload' => (bool) ($fieldRecord->show_on_upload ?? true),
                    'show_on_edit' => (bool) ($fieldRecord->show_on_edit ?? true),
                    'show_in_filters' => (bool) ($fieldRecord->show_in_filters ?? true),
                    'group_key' => $fieldRecord->group_key,
                ],
            ]);
        }

        // For tenant fields, use the service
        $fieldData = $this->fieldService->getField($tenant, $field);

        if (!$fieldData) {
            return response()->json(['error' => 'Field not found'], 404);
        }

        return response()->json([
            'field' => $fieldData,
        ]);
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

        // Check permission - owners and admins have full access
        if (!$this->canManageFields($user, $tenant)) {
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

        // Check permission - owners and admins have full access
        if (!$this->canManageFields($user, $tenant)) {
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

    /**
     * Add an allowed value (option) to a metadata field.
     *
     * POST /tenant/metadata/fields/{field}/values
     *
     * @param Request $request
     * @param int $field
     * @return JsonResponse
     */
    public function addValue(Request $request, int $field): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.fields.values.manage')) {
            abort(403, 'You do not have permission to manage field values.');
        }

        // Verify field belongs to tenant
        $fieldRecord = DB::table('metadata_fields')
            ->where('id', $field)
            ->where('tenant_id', $tenant->id)
            ->where('scope', 'tenant')
            ->first();

        if (!$fieldRecord) {
            return response()->json(['error' => 'Field not found'], 404);
        }

        // Validate request
        $validated = $request->validate([
            'value' => 'required|string|max:255',
            'label' => 'required|string|max:255',
        ]);

        // Check if value already exists
        $existing = DB::table('metadata_options')
            ->where('metadata_field_id', $field)
            ->where('value', $validated['value'])
            ->first();

        if ($existing) {
            return response()->json([
                'error' => 'This value already exists for this field.',
            ], 422);
        }

        try {
            $optionId = DB::table('metadata_options')->insertGetId([
                'metadata_field_id' => $field,
                'value' => $validated['value'],
                'system_label' => $validated['label'],
                'is_system' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('Metadata field value added', [
                'tenant_id' => $tenant->id,
                'field_id' => $field,
                'option_id' => $optionId,
                'value' => $validated['value'],
            ]);

            return response()->json([
                'success' => true,
                'option_id' => $optionId,
                'message' => 'Value added successfully',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to add metadata field value', [
                'tenant_id' => $tenant->id,
                'field_id' => $field,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to add value',
            ], 500);
        }
    }

    /**
     * Remove an allowed value (option) from a metadata field.
     *
     * DELETE /tenant/metadata/fields/{field}/values/{option}
     *
     * @param int $field
     * @param int $option
     * @return JsonResponse
     */
    public function removeValue(int $field, int $option): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.fields.values.manage')) {
            abort(403, 'You do not have permission to manage field values.');
        }

        // Verify field belongs to tenant
        $fieldRecord = DB::table('metadata_fields')
            ->where('id', $field)
            ->where('tenant_id', $tenant->id)
            ->where('scope', 'tenant')
            ->first();

        if (!$fieldRecord) {
            return response()->json(['error' => 'Field not found'], 404);
        }

        // Verify option belongs to field
        $optionRecord = DB::table('metadata_options')
            ->where('id', $option)
            ->where('metadata_field_id', $field)
            ->first();

        if (!$optionRecord) {
            return response()->json(['error' => 'Option not found'], 404);
        }

        try {
            DB::table('metadata_options')
                ->where('id', $option)
                ->delete();

            // If field has ai_eligible=true and no options remain, disable AI
            $remainingOptions = DB::table('metadata_options')
                ->where('metadata_field_id', $field)
                ->count();

            if ($remainingOptions === 0) {
                DB::table('metadata_fields')
                    ->where('id', $field)
                    ->update(['ai_eligible' => false]);
            }

            Log::info('Metadata field value removed', [
                'tenant_id' => $tenant->id,
                'field_id' => $field,
                'option_id' => $option,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Value removed successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to remove metadata field value', [
                'tenant_id' => $tenant->id,
                'field_id' => $field,
                'option_id' => $option,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to remove value',
            ], 500);
        }
    }

    /**
     * Update ai_eligible flag for a field.
     *
     * POST /tenant/metadata/fields/{field}/ai-eligible
     *
     * @param Request $request
     * @param int $field
     * @return JsonResponse
     */
    public function updateAiEligible(Request $request, int $field): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check permission - owners and admins have full access
        if (!$this->canManageFields($user, $tenant, 'metadata.fields.manage')) {
            abort(403, 'You do not have permission to manage metadata fields.');
        }

        // Verify field exists - allow both system fields (scope='system') and tenant fields
        // System fields can have ai_eligible updated even though they can't be fully edited
        $fieldRecord = DB::table('metadata_fields')
            ->where('id', $field)
            ->where(function ($query) use ($tenant) {
                $query->where('scope', 'system')
                    ->orWhere(function ($q) use ($tenant) {
                        $q->where('tenant_id', $tenant->id)
                            ->where('scope', 'tenant');
                    });
            })
            ->first();

        if (!$fieldRecord) {
            return response()->json(['error' => 'Field not found'], 404);
        }

        // Validate request
        $validated = $request->validate([
            'ai_eligible' => 'required|boolean',
        ]);

        // Only allow AI for select/multiselect fields with options
        // Exception: tags field is special and doesn't require options
        $isTagsField = $fieldRecord->key === 'tags';
        
        if ($validated['ai_eligible']) {
            $fieldType = $fieldRecord->type ?? 'text';
            if (!in_array($fieldType, ['select', 'multiselect'], true)) {
                return response()->json([
                    'error' => 'AI suggestions are only available for select and multiselect fields.',
                ], 422);
            }

            // Tags field is special - it doesn't require options for AI suggestions
            if (!$isTagsField) {
                $optionsCount = DB::table('metadata_options')
                    ->where('metadata_field_id', $field)
                    ->count();

                if ($optionsCount === 0) {
                    return response()->json([
                        'error' => 'AI suggestions require at least one allowed value (option) to be defined.',
                    ], 422);
                }
            }
        }

        try {
            DB::table('metadata_fields')
                ->where('id', $field)
                ->update(['ai_eligible' => $validated['ai_eligible']]);

            Log::info('Metadata field AI eligibility updated', [
                'tenant_id' => $tenant->id,
                'field_id' => $field,
                'ai_eligible' => $validated['ai_eligible'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'AI eligibility updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update AI eligibility', [
                'tenant_id' => $tenant->id,
                'field_id' => $field,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to update AI eligibility',
            ], 500);
        }
    }
}
