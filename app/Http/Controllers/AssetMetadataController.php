<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\BulkMetadataService;
use App\Services\MetadataPermissionResolver;
use App\Services\MetadataSchemaResolver;
use App\Services\MetadataApprovalResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Asset Metadata Controller
 *
 * Phase 2 – Step 5.5: Handles AI metadata suggestion review.
 * Phase 2 – Step 6: Handles manual metadata editing.
 */
class AssetMetadataController extends Controller
{
    public function __construct(
        protected MetadataSchemaResolver $metadataSchemaResolver,
        protected BulkMetadataService $bulkMetadataService,
        protected MetadataPermissionResolver $permissionResolver,
        protected MetadataApprovalResolver $approvalResolver
    ) {
    }

    /**
     * Get AI metadata suggestions for an asset.
     *
     * GET /assets/{asset}/metadata/ai-suggestions
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function getAiSuggestions(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Load category for schema resolution
        $category = null;
        if ($asset->metadata && isset($asset->metadata['category_id'])) {
            $categoryId = $asset->metadata['category_id'];
            $category = \App\Models\Category::where('id', $categoryId)
                ->where('tenant_id', $asset->tenant_id)
                ->first();
        }

        if (!$category) {
            return response()->json(['suggestions' => []]);
        }

        // Determine asset type
        $assetType = $this->determineAssetType($asset);

        // Resolve metadata schema
        $schema = $this->metadataSchemaResolver->resolve(
            $asset->tenant_id,
            $asset->brand_id,
            $category->id,
            $assetType
        );

        // Build map of field_id to field definition
        $fieldMap = [];
        foreach ($schema['fields'] ?? [] as $field) {
            $fieldMap[$field['field_id']] = $field;
        }

        // Load AI suggestions (unapproved only, not rejected)
        $aiSuggestions = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->where('asset_metadata.source', 'ai') // Not 'ai_rejected'
            ->whereNull('asset_metadata.approved_at')
            ->select(
                'asset_metadata.id',
                'asset_metadata.metadata_field_id',
                'asset_metadata.value_json',
                'asset_metadata.confidence',
                'metadata_fields.key',
                'metadata_fields.type'
            )
            ->get();

        // Group by field_id (multiple values per field possible)
        $groupedSuggestions = [];
        foreach ($aiSuggestions as $suggestion) {
            $fieldId = $suggestion->metadata_field_id;
            if (!isset($groupedSuggestions[$fieldId])) {
                $fieldDef = $fieldMap[$fieldId] ?? null;
                if (!$fieldDef) {
                    continue; // Skip if field not in resolved schema
                }

                // Phase 4: Check edit permission for this field
                $user = Auth::user();
                $userRole = $user ? ($user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member') : 'member';
                
                $canEdit = $this->permissionResolver->canEdit(
                    $fieldId,
                    $userRole,
                    $tenant->id,
                    $brand->id,
                    $category->id
                );

                $groupedSuggestions[$fieldId] = [
                    'metadata_field_id' => $fieldId,
                    'field_key' => $suggestion->key,
                    'display_label' => $fieldDef['display_label'] ?? $suggestion->key,
                    'type' => $suggestion->type,
                    'options' => $fieldDef['options'] ?? [],
                    'can_edit' => $canEdit, // Phase 4: Permission flag
                    'suggestions' => [],
                ];
            }

            $value = json_decode($suggestion->value_json, true);
            $groupedSuggestions[$fieldId]['suggestions'][] = [
                'id' => $suggestion->id,
                'value' => $value,
                'confidence' => $suggestion->confidence ? (float) $suggestion->confidence : null,
            ];
        }

        return response()->json([
            'suggestions' => array_values($groupedSuggestions),
        ]);
    }

    /**
     * Approve an AI suggestion (accept as-is).
     *
     * POST /assets/{asset}/metadata/ai-suggestions/{suggestionId}/approve
     *
     * @param Asset $asset
     * @param int $suggestionId
     * @return JsonResponse
     */
    public function approveSuggestion(Asset $asset, int $suggestionId): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Verify suggestion exists and is AI, unapproved
        $suggestion = DB::table('asset_metadata')
            ->where('id', $suggestionId)
            ->where('asset_id', $asset->id)
            ->where('source', 'ai')
            ->whereNull('approved_at')
            ->first();

        if (!$suggestion) {
            return response()->json(['message' => 'Suggestion not found'], 404);
        }

        // Check if user already approved a value for this field
        $existingApproved = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $suggestion->metadata_field_id)
            ->where('source', 'user')
            ->whereNotNull('approved_at')
            ->exists();

        if ($existingApproved) {
            return response()->json([
                'message' => 'A user-approved value already exists for this field',
            ], 422);
        }

        DB::transaction(function () use ($suggestion, $suggestionId, $user) {
            // Update suggestion to approved
            DB::table('asset_metadata')
                ->where('id', $suggestionId)
                ->update([
                    'source' => 'user', // Convert AI to user
                    'approved_at' => now(),
                    'approved_by' => $user->id,
                    'updated_at' => now(),
                ]);

            // Create audit history entry
            DB::table('asset_metadata_history')->insert([
                'asset_metadata_id' => $suggestionId,
                'old_value_json' => null,
                'new_value_json' => $suggestion->value_json,
                'source' => 'user',
                'changed_by' => $user->id,
                'created_at' => now(),
            ]);
        });

        Log::info('[AssetMetadataController] AI suggestion approved', [
            'asset_id' => $asset->id,
            'suggestion_id' => $suggestionId,
            'user_id' => $user->id,
        ]);

        return response()->json(['message' => 'Suggestion approved']);
    }

    /**
     * Edit and accept an AI suggestion.
     *
     * POST /assets/{asset}/metadata/ai-suggestions/{suggestionId}/edit-accept
     *
     * @param Request $request
     * @param Asset $asset
     * @param int $suggestionId
     * @return JsonResponse
     */
    public function editAndAcceptSuggestion(Request $request, Asset $asset, int $suggestionId): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Verify suggestion exists and is AI, unapproved
        $suggestion = DB::table('asset_metadata')
            ->where('id', $suggestionId)
            ->where('asset_id', $asset->id)
            ->where('source', 'ai')
            ->whereNull('approved_at')
            ->first();

        if (!$suggestion) {
            return response()->json(['message' => 'Suggestion not found'], 404);
        }

        // Validate edited value
        $validated = $request->validate([
            'value' => 'required',
        ]);

        $editedValue = $validated['value'];

        // Load field definition for validation
        $field = DB::table('metadata_fields')
            ->where('id', $suggestion->metadata_field_id)
            ->first();

        if (!$field) {
            return response()->json(['message' => 'Field not found'], 404);
        }

        // Phase 8: Check if user can approve
        $userRole = $user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member';
        if (!$this->approvalResolver->canApprove($userRole)) {
            return response()->json([
                'message' => 'You do not have permission to approve metadata',
            ], 403);
        }

        // Validate value based on field type
        if (!$this->validateValue($field, $editedValue)) {
            return response()->json([
                'message' => 'Invalid value for field type',
            ], 422);
        }

        // Normalize value
        $normalizedValue = $this->normalizeValue($field, $editedValue);

        DB::transaction(function () use ($asset, $suggestion, $normalizedValue, $user) {
            // Create new user-approved metadata row
            // Phase B7: User-approved AI suggestions have confidence = 1.0 and producer = 'user'
            foreach ($normalizedValue as $value) {
                $assetMetadataId = DB::table('asset_metadata')->insertGetId([
                    'asset_id' => $asset->id,
                    'metadata_field_id' => $suggestion->metadata_field_id,
                    'value_json' => json_encode($value),
                    'source' => 'user',
                    'confidence' => 1.0, // Phase B7: User-approved values are certain
                    'producer' => 'user', // Phase B7: User-approved values are from user
                    'approved_at' => now(),
                    'approved_by' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create audit history entry
                DB::table('asset_metadata_history')->insert([
                    'asset_metadata_id' => $assetMetadataId,
                    'old_value_json' => null,
                    'new_value_json' => json_encode($value),
                    'source' => 'user',
                    'changed_by' => $user->id,
                    'created_at' => now(),
                ]);
            }
        });

        Log::info('[AssetMetadataController] AI suggestion edited and accepted', [
            'asset_id' => $asset->id,
            'suggestion_id' => $suggestionId,
            'user_id' => $user->id,
        ]);

        return response()->json(['message' => 'Suggestion edited and accepted']);
    }

    /**
     * Reject an AI suggestion.
     *
     * POST /assets/{asset}/metadata/ai-suggestions/{suggestionId}/reject
     *
     * @param Asset $asset
     * @param int $suggestionId
     * @return JsonResponse
     */
    public function rejectSuggestion(Asset $asset, int $suggestionId): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Verify suggestion exists and is AI, unapproved
        $suggestion = DB::table('asset_metadata')
            ->where('id', $suggestionId)
            ->where('asset_id', $asset->id)
            ->where('source', 'ai')
            ->whereNull('approved_at')
            ->first();

        if (!$suggestion) {
            return response()->json(['message' => 'Suggestion not found'], 404);
        }

        DB::transaction(function () use ($suggestion, $suggestionId, $user) {
            // Mark as rejected (store in metadata JSON since no rejected_at column)
            // We'll use a soft flag approach - update the row to mark it as rejected
            // Since we can't add columns, we'll use a different approach:
            // Update source to 'ai_rejected' or add a flag in a JSON column
            // For now, we'll just create a history entry and leave the row as-is
            // The frontend will filter out rejected suggestions by checking history

            // Create audit history entry for rejection
            DB::table('asset_metadata_history')->insert([
                'asset_metadata_id' => $suggestionId,
                'old_value_json' => $suggestion->value_json,
                'new_value_json' => null,
                'source' => 'user',
                'changed_by' => $user->id,
                'created_at' => now(),
            ]);

            // Update source to mark as rejected (since no rejected_at column)
            // We'll use a special source value to indicate rejection
            DB::table('asset_metadata')
                ->where('id', $suggestionId)
                ->update([
                    'source' => 'ai_rejected',
                    'updated_at' => now(),
                ]);
        });

        Log::info('[AssetMetadataController] AI suggestion rejected', [
            'asset_id' => $asset->id,
            'suggestion_id' => $suggestionId,
            'user_id' => $user->id,
        ]);

        return response()->json(['message' => 'Suggestion rejected']);
    }

    /**
     * Determine asset type for schema resolution.
     *
     * @param Asset $asset
     * @return string
     */
    /**
     * Determine file type for metadata schema resolution.
     * 
     * Note: asset->type is organizational (asset/marketing/ai_generated),
     * but MetadataSchemaResolver expects file type (image/video/document).
     * 
     * This method infers file type from asset's mime_type and filename.
     *
     * @param Asset $asset
     * @return string One of: 'image', 'video', 'document'
     */
    protected function determineAssetType(Asset $asset): string
    {
        $mimeType = $asset->mime_type ?? '';
        $filename = $asset->original_filename ?? '';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // Video types
        if (str_starts_with($mimeType, 'video/') || in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm'])) {
            return 'video';
        }
        
        // Document types (PDF, office docs)
        if ($mimeType === 'application/pdf' || $extension === 'pdf' ||
            in_array($extension, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']) ||
            str_starts_with($mimeType, 'application/msword') ||
            str_starts_with($mimeType, 'application/vnd.ms-excel') ||
            str_starts_with($mimeType, 'application/vnd.ms-powerpoint') ||
            str_starts_with($mimeType, 'application/vnd.openxmlformats')) {
            return 'document';
        }
        
        // Image types (default)
        // Includes: jpg, jpeg, png, gif, webp, bmp, svg, psd, ai, etc.
        if (str_starts_with($mimeType, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'psd', 'ai', 'tif', 'tiff'])) {
            return 'image';
        }
        
        // Default to 'image' if type cannot be determined
        // Most assets in DAM systems are images
        return 'image';
    }

    /**
     * Validate value against field type.
     *
     * @param object $field
     * @param mixed $value
     * @return bool
     */
    protected function validateValue(object $field, $value): bool
    {
        $fieldType = $field->type ?? 'text';

        if ($fieldType === 'multiselect') {
            return is_array($value) && !empty($value);
        }

        switch ($fieldType) {
            case 'number':
                return is_numeric($value);
            case 'boolean':
                return is_bool($value);
            case 'date':
                return $this->isValidDate($value);
            case 'text':
                return is_string($value) && $value !== '';
            case 'select':
                return $value !== null && $value !== '';
            default:
                return true;
        }
    }

    /**
     * Check if value is a valid date.
     *
     * @param mixed $value
     * @return bool
     */
    protected function isValidDate($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        try {
            new \DateTime($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Normalize value based on field type.
     *
     * @param object $field
     * @param mixed $value
     * @return array
     */
    protected function normalizeValue(object $field, $value): array
    {
        $fieldType = $field->type ?? 'text';

        if ($fieldType === 'multiselect') {
            if (!is_array($value)) {
                return [];
            }
            return array_map(fn($v) => $v, array_unique($value, SORT_REGULAR));
        }

        return [$value];
    }

    /**
     * Get editable metadata for an asset.
     *
     * GET /assets/{asset}/metadata/editable
     *
     * Phase 2 – Step 6: Returns metadata fields that can be edited.
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function getEditableMetadata(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Phase 4: Get user role for permission checks
        $userRole = $user ? ($user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member') : 'member';

        // Load category for schema resolution
        $category = null;
        if ($asset->metadata && isset($asset->metadata['category_id'])) {
            $categoryId = $asset->metadata['category_id'];
            $category = \App\Models\Category::where('id', $categoryId)
                ->where('tenant_id', $asset->tenant_id)
                ->first();
        }

        if (!$category) {
            return response()->json(['fields' => []]);
        }

        // Determine asset type
        $assetType = $this->determineAssetType($asset);

        // Resolve metadata schema
        $schema = $this->metadataSchemaResolver->resolve(
            $asset->tenant_id,
            $asset->brand_id,
            $category->id,
            $assetType
        );

        // Load current approved metadata values
        // Phase B5: For hybrid fields, we need to check both automatic and manual_override sources
        // Priority: manual_override > user > automatic/ai
        $currentMetadataRows = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->whereIn('asset_metadata.source', ['user', 'automatic', 'ai', 'manual_override'])
            ->whereNotNull('asset_metadata.approved_at')
            ->select(
                'metadata_fields.id as metadata_field_id',
                'metadata_fields.key',
                'metadata_fields.type',
                'asset_metadata.value_json',
                'asset_metadata.source',
                'asset_metadata.overridden_at',
                'asset_metadata.overridden_by'
            )
            ->orderByRaw("
                CASE 
                    WHEN asset_metadata.source = 'manual_override' THEN 1
                    WHEN asset_metadata.source = 'user' THEN 2
                    WHEN asset_metadata.source = 'automatic' THEN 3
                    WHEN asset_metadata.source = 'ai' THEN 4
                    ELSE 5
                END
            ")
            ->orderBy('asset_metadata.approved_at', 'desc') // Most recent first within same source priority
            ->get()
            ->groupBy('metadata_field_id');

        // Build map of field_id to current values and override state
        $fieldValues = [];
        $fieldOverrideState = []; // Phase B5: Track override state for hybrid fields
        foreach ($currentMetadataRows as $fieldId => $rows) {
            // Get field type and most recent row (highest priority after ordering)
            $mostRecent = $rows->first();
            $fieldType = $mostRecent->type ?? 'text';
            $source = $mostRecent->source ?? null;

            if ($fieldType === 'multiselect') {
                // For multiselect, collect all unique values from all rows
                $allValues = [];
                foreach ($rows as $row) {
                    $value = json_decode($row->value_json, true);
                    if (is_array($value)) {
                        $allValues = array_merge($allValues, $value);
                    } else {
                        $allValues[] = $value;
                    }
                }
                $fieldValues[$fieldId] = array_unique($allValues, SORT_REGULAR);
            } else {
                // For single-value fields, use the most recent value (highest priority)
                $fieldValues[$fieldId] = json_decode($mostRecent->value_json, true);
            }

            // Phase B5: Track override state
            $fieldOverrideState[$fieldId] = [
                'source' => $source,
                'is_overridden' => $source === 'manual_override',
                'overridden_at' => $mostRecent->overridden_at ?? null,
                'overridden_by' => $mostRecent->overridden_by ?? null,
            ];
        }

        // Phase 8: Check for pending metadata per field
        $pendingMetadata = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->whereNull('approved_at')
            ->whereNotIn('source', ['user_rejected', 'ai_rejected'])
            ->pluck('metadata_field_id')
            ->toArray();
        $pendingFieldIds = array_unique($pendingMetadata);

        // Filter to editable fields only
        $editableFields = [];
        foreach ($schema['fields'] ?? [] as $field) {
            // Exclude internal-only fields
            if ($field['is_internal_only'] ?? false) {
                continue;
            }

            // Exclude non-editable fields
            // Load is_user_editable from database (not in resolved schema)
            $fieldDef = DB::table('metadata_fields')
                ->where('id', $field['field_id'])
                ->first();

            if (!$fieldDef || !($fieldDef->is_user_editable ?? true)) {
                continue;
            }

            // Phase B3: Exclude fields that should not appear in edit view
            $showOnEdit = $field['show_on_edit'] ?? true;
            if (!$showOnEdit) {
                continue; // Filter-only fields: hidden from edit UI
            }

            // Phase 4: Check edit permission
            $canEdit = $this->permissionResolver->canEdit(
                $field['field_id'],
                $userRole,
                $tenant->id,
                $brand->id,
                $category->id
            );

            // Skip fields user cannot edit
            if (!$canEdit) {
                continue;
            }

            // Get current value(s) for this field
            $currentValue = $fieldValues[$field['field_id']] ?? null;

            // Phase B2: Check if field is readonly (automatic or explicitly readonly)
            $populationMode = $field['population_mode'] ?? 'manual';
            $isReadonly = ($field['readonly'] ?? false) || ($populationMode === 'automatic');

            $editableFields[] = [
                'metadata_field_id' => $field['field_id'],
                'field_key' => $field['key'],
                'display_label' => $field['display_label'] ?? $field['key'],
                'type' => $field['type'],
                'options' => $field['options'] ?? [],
                'is_user_editable' => true,
                'can_edit' => true, // Phase 4: Permission already checked
                'current_value' => $currentValue,
                'has_pending' => in_array($field['field_id'], $pendingFieldIds), // Phase 8
                // Phase B2: Add readonly and population_mode flags
                'readonly' => $isReadonly,
                'population_mode' => $populationMode,
            ];
        }

        return response()->json([
            'fields' => $editableFields,
        ]);
    }

    /**
     * Persist manual metadata edit.
     *
     * POST /assets/{asset}/metadata/edit
     *
     * Phase 2 – Step 6: Creates new metadata row for manual edit.
     *
     * @param Request $request
     * @param Asset $asset
     * @return JsonResponse
     */
    public function editMetadata(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Validate request
        $validated = $request->validate([
            'metadata_field_id' => 'required|integer|exists:metadata_fields,id',
            'value' => 'required',
        ]);

        $fieldId = $validated['metadata_field_id'];

        // Phase 4: Check edit permission
        $userRole = $user ? ($user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member') : 'member';
        
        // Load category for permission check
        $category = null;
        if ($asset->metadata && isset($asset->metadata['category_id'])) {
            $categoryId = $asset->metadata['category_id'];
            $category = \App\Models\Category::where('id', $categoryId)
                ->where('tenant_id', $asset->tenant_id)
                ->first();
        }

        $canEdit = $this->permissionResolver->canEdit(
            $fieldId,
            $userRole,
            $tenant->id,
            $brand->id,
            $category?->id
        );

        if (!$canEdit) {
            return response()->json([
                'message' => 'You do not have permission to edit this metadata field.',
            ], 403);
        }
        $newValue = $validated['value'];

        // Load category for schema resolution
        $category = null;
        if ($asset->metadata && isset($asset->metadata['category_id'])) {
            $categoryId = $asset->metadata['category_id'];
            $category = \App\Models\Category::where('id', $categoryId)
                ->where('tenant_id', $asset->tenant_id)
                ->first();
        }

        if (!$category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        // Determine asset type
        $assetType = $this->determineAssetType($asset);

        // Resolve metadata schema to validate field
        $schema = $this->metadataSchemaResolver->resolve(
            $asset->tenant_id,
            $asset->brand_id,
            $category->id,
            $assetType
        );

        // Find field in schema
        $fieldDef = null;
        foreach ($schema['fields'] ?? [] as $field) {
            if ($field['field_id'] === $fieldId) {
                $fieldDef = $field;
                break;
            }
        }

        if (!$fieldDef) {
            return response()->json(['message' => 'Field not found in schema'], 404);
        }

        // Check if field is user-editable
        $field = DB::table('metadata_fields')
            ->where('id', $fieldId)
            ->first();

        if (!$field || !($field->is_user_editable ?? true)) {
            return response()->json(['message' => 'Field is not editable'], 422);
        }

        // Check if field is internal-only
        if ($fieldDef['is_internal_only'] ?? false) {
            return response()->json(['message' => 'Field is internal-only'], 422);
        }

        // Phase B2: Check if field is readonly (automatic or explicitly readonly)
        $populationMode = $fieldDef['population_mode'] ?? 'manual';
        $isReadonly = ($fieldDef['readonly'] ?? false) || ($populationMode === 'automatic');
        
        // Phase B5: For hybrid fields, require explicit override intent
        $isHybrid = $populationMode === 'hybrid';
        $requiresOverride = $isHybrid && !$request->has('override_intent') && !$request->boolean('override_intent');
        
        if ($isReadonly) {
            return response()->json([
                'message' => 'This field is automatically populated and cannot be edited.',
            ], 422);
        }
        
        if ($requiresOverride) {
            return response()->json([
                'message' => 'This field requires explicit override intent. Use the override action to enable editing.',
                'requires_override' => true,
            ], 422);
        }

        // Validate value
        if (!$this->validateValue($field, $newValue)) {
            return response()->json([
                'message' => 'Invalid value for field type',
            ], 422);
        }

        // Get previous approved value for audit
        // Phase B5: Check for manual_override or user sources
        $previousValue = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $fieldId)
            ->whereIn('source', ['user', 'manual_override'])
            ->whereNotNull('approved_at')
            ->orderByRaw("
                CASE 
                    WHEN source = 'manual_override' THEN 1
                    WHEN source = 'user' THEN 2
                    ELSE 3
                END ASC
            ")
            ->orderBy('approved_at', 'desc')
            ->first();

        $oldValueJson = $previousValue ? $previousValue->value_json : null;

        // Normalize value
        $normalizedValues = $this->normalizeValue($field, $newValue);

        // Phase 8: Check if approval is required
        $requiresApproval = $this->approvalResolver->requiresApproval('user', $tenant);

        // Phase B5: Determine source based on override intent for hybrid fields
        $isHybrid = $populationMode === 'hybrid';
        $hasOverrideIntent = $validated['override_intent'] ?? false;
        $source = ($isHybrid && $hasOverrideIntent) ? 'manual_override' : 'user';
        $overriddenAt = ($isHybrid && $hasOverrideIntent) ? now() : null;
        $overriddenBy = ($isHybrid && $hasOverrideIntent) ? $user->id : null;

        // Persist in transaction
        DB::transaction(function () use ($asset, $fieldId, $normalizedValues, $user, $oldValueJson, $requiresApproval, $source, $overriddenAt, $overriddenBy) {
            foreach ($normalizedValues as $value) {
                // Create new asset_metadata row (never update existing)
                $assetMetadataId = DB::table('asset_metadata')->insertGetId([
                    'asset_id' => $asset->id,
                    'metadata_field_id' => $fieldId,
                    'value_json' => json_encode($value),
                    'source' => $source,
                    'confidence' => null,
                    'approved_at' => $requiresApproval ? null : now(),
                    'approved_by' => $requiresApproval ? null : $user->id,
                    'overridden_at' => $overriddenAt,
                    'overridden_by' => $overriddenBy,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create audit history entry
                DB::table('asset_metadata_history')->insert([
                    'asset_metadata_id' => $assetMetadataId,
                    'old_value_json' => $oldValueJson,
                    'new_value_json' => json_encode($value),
                    'source' => 'user',
                    'changed_by' => $user->id,
                    'created_at' => now(),
                ]);
            }
        });

        Log::info('[AssetMetadataController] Metadata edited', [
            'asset_id' => $asset->id,
            'metadata_field_id' => $fieldId,
            'user_id' => $user->id,
        ]);

        return response()->json(['message' => 'Metadata updated']);
    }

    /**
     * Override a hybrid metadata field.
     *
     * POST /assets/{asset}/metadata/override
     *
     * Phase B5: Explicitly marks a hybrid field as overridden, enabling editing.
     *
     * @param Request $request
     * @param Asset $asset
     * @return JsonResponse
     */
    public function overrideHybridField(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Validate request
        $validated = $request->validate([
            'metadata_field_id' => 'required|integer|exists:metadata_fields,id',
        ]);

        $fieldId = $validated['metadata_field_id'];

        // Load category for schema resolution
        $category = null;
        if ($asset->metadata && isset($asset->metadata['category_id'])) {
            $categoryId = $asset->metadata['category_id'];
            $category = \App\Models\Category::where('id', $categoryId)
                ->where('tenant_id', $asset->tenant_id)
                ->first();
        }

        if (!$category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        // Determine asset type
        $assetType = $this->determineAssetType($asset);

        // Resolve metadata schema to validate field
        $schema = $this->metadataSchemaResolver->resolve(
            $asset->tenant_id,
            $asset->brand_id,
            $category->id,
            $assetType
        );

        // Find field in schema
        $fieldDef = null;
        foreach ($schema['fields'] ?? [] as $field) {
            if ($field['field_id'] === $fieldId) {
                $fieldDef = $field;
                break;
            }
        }

        if (!$fieldDef) {
            return response()->json(['message' => 'Field not found in schema'], 404);
        }

        // Verify field is hybrid
        $populationMode = $fieldDef['population_mode'] ?? 'manual';
        if ($populationMode !== 'hybrid') {
            return response()->json([
                'message' => 'This field is not a hybrid field and does not require override.',
            ], 422);
        }

        // Check if already overridden
        $existingOverride = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $fieldId)
            ->where('source', 'manual_override')
            ->whereNotNull('approved_at')
            ->first();

        if ($existingOverride) {
            return response()->json([
                'message' => 'This field is already overridden.',
                'overridden_at' => $existingOverride->overridden_at,
                'overridden_by' => $existingOverride->overridden_by,
            ], 200);
        }

        // Get current automatic value
        $currentAutomatic = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $fieldId)
            ->whereIn('source', ['automatic', 'ai'])
            ->whereNotNull('approved_at')
            ->orderBy('approved_at', 'desc')
            ->first();

        if (!$currentAutomatic) {
            return response()->json([
                'message' => 'No automatic value found for this field.',
            ], 404);
        }

        // Create override record (same value, but marked as manual_override)
        // This enables editing while preserving the automatic value
        // Phase B7: Manual overrides have confidence = 1.0 and producer = 'user'
        DB::table('asset_metadata')->insert([
            'asset_id' => $asset->id,
            'metadata_field_id' => $fieldId,
            'value_json' => $currentAutomatic->value_json,
            'source' => 'manual_override',
            'confidence' => 1.0, // Phase B7: Manual overrides are certain
            'producer' => 'user', // Phase B7: Manual overrides are from user
            'approved_at' => now(),
            'approved_by' => $user->id,
            'overridden_at' => now(),
            'overridden_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('[AssetMetadataController] Hybrid field overridden', [
            'asset_id' => $asset->id,
            'metadata_field_id' => $fieldId,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Field override enabled. You can now edit this field.',
            'overridden_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Revert a hybrid field to automatic value.
     *
     * POST /assets/{asset}/metadata/revert
     *
     * Phase B5: Removes manual override, reverting to automatic value.
     *
     * @param Request $request
     * @param Asset $asset
     * @return JsonResponse
     */
    public function revertToAutomatic(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Validate request
        $validated = $request->validate([
            'metadata_field_id' => 'required|integer|exists:metadata_fields,id',
        ]);

        $fieldId = $validated['metadata_field_id'];

        // Find existing override
        $existingOverride = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $fieldId)
            ->where('source', 'manual_override')
            ->whereNotNull('approved_at')
            ->first();

        if (!$existingOverride) {
            return response()->json([
                'message' => 'No override found for this field.',
            ], 404);
        }

        // Get automatic value
        $automaticValue = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $fieldId)
            ->whereIn('source', ['automatic', 'ai'])
            ->whereNotNull('approved_at')
            ->orderBy('approved_at', 'desc')
            ->first();

        if (!$automaticValue) {
            return response()->json([
                'message' => 'No automatic value found to revert to.',
            ], 404);
        }

        // Create new record with automatic value (effectively reverting)
        // We don't delete the override - we create a new record with automatic source
        // Phase B7: Restore automatic confidence and producer when reverting
        DB::table('asset_metadata')->insert([
            'asset_id' => $asset->id,
            'metadata_field_id' => $fieldId,
            'value_json' => $automaticValue->value_json,
            'source' => 'automatic',
            'confidence' => $automaticValue->confidence, // Phase B7: Restore automatic confidence
            'producer' => $automaticValue->producer, // Phase B7: Restore automatic producer
            'approved_at' => now(),
            'approved_by' => $user->id,
            'overridden_at' => null,
            'overridden_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('[AssetMetadataController] Hybrid field reverted to automatic', [
            'asset_id' => $asset->id,
            'metadata_field_id' => $fieldId,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Field reverted to automatic value.',
        ]);
    }

    /**
     * Preview bulk metadata operation.
     *
     * POST /assets/metadata/bulk/preview
     *
     * Phase 2 – Step 7: Previews bulk metadata changes without writing.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function previewBulk(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        if (!$tenant || !$brand) {
            return response()->json(['message' => 'Tenant or brand not found'], 404);
        }

        // Validate request
        $validated = $request->validate([
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'required|uuid|exists:assets,id',
            'operation_type' => 'required|string|in:add,replace,clear',
            'metadata' => 'required|array',
        ]);

        try {
            // Phase 4: Get user role for permission checks
            $userRole = $user ? ($user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member') : 'member';

            $preview = $this->bulkMetadataService->preview(
                $validated['asset_ids'],
                $validated['operation_type'],
                $validated['metadata'],
                $tenant->id,
                $brand->id,
                $userRole
            );

            // Generate preview token (simple hash for now)
            $previewToken = hash('sha256', json_encode($validated) . $user->id . now()->timestamp);

            // Store preview token in session (simple approach)
            session(['bulk_metadata_preview_' . $previewToken => [
                'asset_ids' => $validated['asset_ids'],
                'operation_type' => $validated['operation_type'],
                'metadata' => $validated['metadata'],
                'expires_at' => now()->addMinutes(10),
            ]]);

            return response()->json([
                'preview' => $preview,
                'preview_token' => $previewToken,
            ]);
        } catch (\Exception $e) {
            Log::error('[AssetMetadataController] Bulk preview failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Preview failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Execute bulk metadata operation.
     *
     * POST /assets/metadata/bulk/execute
     *
     * Phase 2 – Step 7: Executes bulk metadata changes.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function executeBulk(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        if (!$tenant || !$brand) {
            return response()->json(['message' => 'Tenant or brand not found'], 404);
        }

        // Validate request
        $validated = $request->validate([
            'preview_token' => 'required|string',
        ]);

        $previewToken = $validated['preview_token'];
        $previewData = session('bulk_metadata_preview_' . $previewToken);

        if (!$previewData) {
            return response()->json(['message' => 'Preview token not found or expired'], 404);
        }

        // Check expiration
        if (now()->greaterThan($previewData['expires_at'])) {
            session()->forget('bulk_metadata_preview_' . $previewToken);
            return response()->json(['message' => 'Preview token expired'], 410);
        }

        try {
            // Phase 4: Get user role for permission checks
            $userRole = $user ? ($user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member') : 'member';

            // Re-validate everything
            $results = $this->bulkMetadataService->execute(
                $previewData['asset_ids'],
                $previewData['operation_type'],
                $previewData['metadata'],
                $tenant->id,
                $brand->id,
                $user->id,
                $userRole
            );

            // Clear preview token
            session()->forget('bulk_metadata_preview_' . $previewToken);

            Log::info('[AssetMetadataController] Bulk metadata executed', [
                'total_assets' => $results['total_assets'],
                'successes' => count($results['successes']),
                'failures' => count($results['failures']),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            Log::error('[AssetMetadataController] Bulk execute failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Execution failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get filterable metadata schema.
     *
     * GET /assets/metadata/filterable-schema
     *
     * Phase 2 – Step 8: Returns filterable fields for current context.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getFilterableSchema(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');

        if (!$tenant || !$brand) {
            return response()->json(['fields' => []]);
        }

        // Get category from request
        $categoryId = $request->get('category_id');
        if (!$categoryId) {
            return response()->json(['fields' => []]);
        }

        $category = \App\Models\Category::where('id', $categoryId)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$category) {
            return response()->json(['fields' => []]);
        }

        // Determine file type for metadata schema resolution
        // Note: category->asset_type is organizational (asset/marketing/ai_generated),
        // but MetadataSchemaResolver expects file type (image/video/document)
        // Default to 'image' when we don't have an asset object to infer from
        // TODO: Could infer from actual assets in category or add file_type to categories
        $fileType = 'image'; // Default file type for metadata schema resolution

        // Resolve metadata schema
        $schema = $this->metadataSchemaResolver->resolve(
            $tenant->id,
            $brand->id,
            $category->id,
            $fileType
        );

        // Get filterable fields
        $filterService = app(\App\Services\MetadataFilterService::class);
        $filterableFields = $filterService->getFilterableFields($schema);

        return response()->json([
            'fields' => $filterableFields,
        ]);
    }

    /**
     * Get saved views for current user/tenant.
     *
     * GET /assets/metadata/saved-views
     *
     * Phase 2 – Step 8: Returns saved filter views.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSavedViews(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['views' => []]);
        }

        $categoryId = $request->get('category_id');

        $query = DB::table('saved_views')
            ->where('tenant_id', $tenant->id)
            ->where(function ($q) use ($user) {
                $q->where('is_global', true);
                if ($user) {
                    $q->orWhere('user_id', $user->id);
                }
            });

        if ($categoryId) {
            $query->where(function ($q) use ($categoryId) {
                $q->where('category_id', $categoryId)
                    ->orWhereNull('category_id');
            });
        }

        $views = $query->orderBy('name')
            ->get()
            ->map(function ($view) {
                return [
                    'id' => $view->id,
                    'name' => $view->name,
                    'filters' => json_decode($view->filters, true),
                    'category_id' => $view->category_id,
                    'is_global' => (bool) $view->is_global,
                ];
            });

        return response()->json(['views' => $views]);
    }

    /**
     * Save a filter view.
     *
     * POST /assets/metadata/saved-views
     *
     * Phase 2 – Step 8: Saves current filter configuration.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function saveView(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant || !$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'filters' => 'required|array',
            'category_id' => 'nullable|integer|exists:categories,id',
            'is_global' => 'nullable|boolean',
        ]);

        // Only admins can create global views
        $isGlobal = ($validated['is_global'] ?? false) && $user->can('manage categories');

        $viewId = DB::table('saved_views')->insertGetId([
            'tenant_id' => $tenant->id,
            'user_id' => $isGlobal ? null : $user->id,
            'name' => $validated['name'],
            'filters' => json_encode($validated['filters']),
            'category_id' => $validated['category_id'] ?? null,
            'is_global' => $isGlobal,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'id' => $viewId,
            'message' => 'View saved',
        ]);
    }

    /**
     * Delete a saved view.
     *
     * DELETE /assets/metadata/saved-views/{viewId}
     *
     * Phase 2 – Step 8: Deletes a saved filter view.
     *
     * @param int $viewId
     * @return JsonResponse
     */
    public function deleteView(int $viewId): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (!$tenant || !$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Verify view belongs to tenant and user (or is global and user is admin)
        $view = DB::table('saved_views')
            ->where('id', $viewId)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$view) {
            return response()->json(['message' => 'View not found'], 404);
        }

        // Check permissions
        if ($view->is_global && !$user->can('manage categories')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$view->is_global && $view->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        DB::table('saved_views')->where('id', $viewId)->delete();

        return response()->json(['message' => 'View deleted']);
    }

    /**
     * Get pending metadata for an asset (Phase 8).
     *
     * GET /assets/{asset}/metadata/pending
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function getPendingMetadata(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Load category for schema resolution
        $category = null;
        if ($asset->metadata && isset($asset->metadata['category_id'])) {
            $categoryId = $asset->metadata['category_id'];
            $category = \App\Models\Category::where('id', $categoryId)
                ->where('tenant_id', $asset->tenant_id)
                ->first();
        }

        if (!$category) {
            return response()->json(['pending' => []]);
        }

        // Determine asset type
        $assetType = $this->determineAssetType($asset);

        // Resolve metadata schema
        $schema = $this->metadataSchemaResolver->resolve(
            $asset->tenant_id,
            $asset->brand_id,
            $category->id,
            $assetType
        );

        // Build map of field_id to field definition
        $fieldMap = [];
        foreach ($schema['fields'] ?? [] as $field) {
            $fieldMap[$field['field_id']] = $field;
        }

        // Load pending metadata (unapproved, not rejected)
        $pendingMetadata = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->whereNull('asset_metadata.approved_at')
            ->whereNotIn('asset_metadata.source', ['user_rejected', 'ai_rejected'])
            ->select(
                'asset_metadata.id',
                'asset_metadata.metadata_field_id',
                'asset_metadata.value_json',
                'asset_metadata.confidence',
                'asset_metadata.source',
                'asset_metadata.created_at',
                'metadata_fields.key',
                'metadata_fields.system_label',
                'metadata_fields.type'
            )
            ->orderBy('asset_metadata.created_at', 'desc')
            ->get();

        // Group by field_id
        $groupedPending = [];
        $userRole = $user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member';
        $canApprove = $this->approvalResolver->canApprove($userRole);

        foreach ($pendingMetadata as $pending) {
            $fieldId = $pending->metadata_field_id;
            if (!isset($groupedPending[$fieldId])) {
                $fieldDef = $fieldMap[$fieldId] ?? null;
                if (!$fieldDef) {
                    continue; // Skip if field not in resolved schema
                }

                $groupedPending[$fieldId] = [
                    'field_id' => $fieldId,
                    'field_key' => $pending->key,
                    'field_label' => $pending->system_label,
                    'field_type' => $pending->type,
                    'options' => $fieldDef['options'] ?? [], // Include options for edit modal
                    'values' => [],
                    'can_approve' => $canApprove,
                ];
            }

            $groupedPending[$fieldId]['values'][] = [
                'id' => $pending->id,
                'value' => json_decode($pending->value_json, true),
                'source' => $pending->source,
                'confidence' => $pending->confidence,
                'created_at' => $pending->created_at,
            ];
        }

        return response()->json([
            'pending' => array_values($groupedPending),
        ]);
    }

    /**
     * Approve a pending metadata value (Phase 8).
     *
     * POST /metadata/{metadataId}/approve
     *
     * @param int $metadataId
     * @return JsonResponse
     */
    public function approveMetadata(int $metadataId): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Load metadata
        $metadata = DB::table('asset_metadata')
            ->where('id', $metadataId)
            ->first();

        if (!$metadata) {
            return response()->json(['message' => 'Metadata not found'], 404);
        }

        // Load asset to verify tenant/brand
        $asset = Asset::find($metadata->asset_id);
        if (!$asset || $asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Verify metadata is pending
        if ($metadata->approved_at) {
            return response()->json(['message' => 'Metadata is already approved'], 422);
        }

        // Check if user can approve
        $userRole = $user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member';
        if (!$this->approvalResolver->canApprove($userRole)) {
            return response()->json([
                'message' => 'You do not have permission to approve metadata',
            ], 403);
        }

        DB::transaction(function () use ($metadata, $metadataId, $user) {
            // Update metadata to approved
            DB::table('asset_metadata')
                ->where('id', $metadataId)
                ->update([
                    'approved_at' => now(),
                    'approved_by' => $user->id,
                    'updated_at' => now(),
                ]);

            // Create audit history entry
            DB::table('asset_metadata_history')->insert([
                'asset_metadata_id' => $metadataId,
                'old_value_json' => null,
                'new_value_json' => $metadata->value_json,
                'source' => $metadata->source === 'ai' ? 'user' : 'user',
                'changed_by' => $user->id,
                'created_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Metadata approved']);
    }

    /**
     * Edit and approve a pending metadata value (Phase 8).
     *
     * POST /metadata/{metadataId}/edit-approve
     *
     * @param Request $request
     * @param int $metadataId
     * @return JsonResponse
     */
    public function editAndApproveMetadata(Request $request, int $metadataId): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Validate request
        $validated = $request->validate([
            'value' => 'required',
        ]);

        // Load metadata
        $metadata = DB::table('asset_metadata')
            ->where('id', $metadataId)
            ->first();

        if (!$metadata) {
            return response()->json(['message' => 'Metadata not found'], 404);
        }

        // Load asset to verify tenant/brand
        $asset = Asset::find($metadata->asset_id);
        if (!$asset || $asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Verify metadata is pending
        if ($metadata->approved_at) {
            return response()->json(['message' => 'Metadata is already approved'], 422);
        }

        // Check if user can approve
        $userRole = $user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member';
        if (!$this->approvalResolver->canApprove($userRole)) {
            return response()->json([
                'message' => 'You do not have permission to approve metadata',
            ], 403);
        }

        // Load field definition for validation
        $field = DB::table('metadata_fields')
            ->where('id', $metadata->metadata_field_id)
            ->first();

        if (!$field) {
            return response()->json(['message' => 'Field not found'], 404);
        }

        $editedValue = $validated['value'];

        // Validate value
        if (!$this->validateValue($field, $editedValue)) {
            return response()->json([
                'message' => 'Invalid value for field type',
            ], 422);
        }

        // Normalize value
        $normalizedValues = $this->normalizeValue($field, $editedValue);

        DB::transaction(function () use ($asset, $metadata, $normalizedValues, $user) {
            // Create new approved metadata row (leave proposal untouched)
            // Phase B7: User-edited and approved values have confidence = 1.0 and producer = 'user'
            foreach ($normalizedValues as $value) {
                $assetMetadataId = DB::table('asset_metadata')->insertGetId([
                    'asset_id' => $asset->id,
                    'metadata_field_id' => $metadata->metadata_field_id,
                    'value_json' => json_encode($value),
                    'source' => 'user',
                    'confidence' => 1.0, // Phase B7: User-edited values are certain
                    'producer' => 'user', // Phase B7: User-edited values are from user
                    'approved_at' => now(),
                    'approved_by' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create audit history entry
                DB::table('asset_metadata_history')->insert([
                    'asset_metadata_id' => $assetMetadataId,
                    'old_value_json' => $metadata->value_json,
                    'new_value_json' => json_encode($value),
                    'source' => 'user',
                    'changed_by' => $user->id,
                    'created_at' => now(),
                ]);
            }
        });

        return response()->json(['message' => 'Metadata edited and approved']);
    }

    /**
     * Reject a pending metadata value (Phase 8).
     *
     * POST /metadata/{metadataId}/reject
     *
     * @param int $metadataId
     * @return JsonResponse
     */
    public function rejectMetadata(int $metadataId): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Load metadata
        $metadata = DB::table('asset_metadata')
            ->where('id', $metadataId)
            ->first();

        if (!$metadata) {
            return response()->json(['message' => 'Metadata not found'], 404);
        }

        // Load asset to verify tenant/brand
        $asset = Asset::find($metadata->asset_id);
        if (!$asset || $asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Verify metadata is pending
        if ($metadata->approved_at) {
            return response()->json(['message' => 'Metadata is already approved'], 422);
        }

        // Check if user can approve (rejection requires same permission)
        $userRole = $user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member';
        if (!$this->approvalResolver->canApprove($userRole)) {
            return response()->json([
                'message' => 'You do not have permission to reject metadata',
            ], 403);
        }

        DB::transaction(function () use ($metadata, $metadataId, $user) {
            // Mark as rejected by updating source
            $rejectedSource = $metadata->source === 'ai' ? 'ai_rejected' : 'user_rejected';
            DB::table('asset_metadata')
                ->where('id', $metadataId)
                ->update([
                    'source' => $rejectedSource,
                    'updated_at' => now(),
                ]);

            // Create audit history entry
            DB::table('asset_metadata_history')->insert([
                'asset_metadata_id' => $metadataId,
                'old_value_json' => $metadata->value_json,
                'new_value_json' => null,
                'source' => 'user',
                'changed_by' => $user->id,
                'created_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Metadata rejected']);
    }
}
