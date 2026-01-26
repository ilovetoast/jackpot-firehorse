<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Models\Asset;
use App\Services\ActivityRecorder;
use App\Services\AiMetadataConfidenceService;
use App\Services\AiMetadataSuggestionService;
use App\Services\BulkMetadataService;
use App\Services\MetadataPermissionResolver;
use App\Services\MetadataSchemaResolver;
use App\Services\MetadataApprovalResolver;
use App\Services\PlanService;
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
        protected MetadataApprovalResolver $approvalResolver,
        protected AiMetadataConfidenceService $confidenceService,
        protected AiMetadataSuggestionService $suggestionService,
        protected PlanService $planService
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
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Check permission - viewers cannot see AI suggestions
        if (!$user->hasPermissionForTenant($tenant, 'metadata.suggestions.view')) {
            return response()->json(['message' => 'Permission denied'], 403);
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

        // Check permission - only users with metadata.suggestions.apply can approve
        if (!$user->hasPermissionForTenant($tenant, 'metadata.suggestions.apply')) {
            return response()->json([
                'message' => 'You do not have permission to approve suggestions.',
            ], 403);
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

        // Phase 8: Check if user can approve (permission-based)
        if (!$this->approvalResolver->canApprove($user, $tenant)) {
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

        // Get field info for activity logging
        $fieldKey = $field->key ?? 'unknown';
        $fieldLabel = $field->system_label ?? $fieldKey;
        $brand = app('brand');

        DB::transaction(function () use ($asset, $suggestion, $normalizedValue, $user, $fieldKey, $fieldLabel, $tenant, $brand) {
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

            // Log activity: User approved AI suggestion
            try {
                ActivityRecorder::record(
                    tenant: $tenant,
                    eventType: EventType::ASSET_METADATA_UPDATED,
                    subject: $asset,
                    actor: $user,
                    brand: $brand,
                    metadata: [
                        'field_key' => $fieldKey,
                        'field_label' => $fieldLabel,
                        'field_id' => $suggestion->metadata_field_id,
                        'action' => 'ai_suggestion_approved',
                        'value' => json_encode($normalizedValue),
                        'suggestion_id' => $suggestion->id,
                    ]
                );
            } catch (\Exception $e) {
                // Activity logging must never break processing
                Log::error('Failed to log AI suggestion approval activity', [
                    'asset_id' => $asset->id,
                    'suggestion_id' => $suggestion->id,
                    'error' => $e->getMessage(),
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

        // Check permission - only users with metadata.suggestions.dismiss can reject
        if (!$user->hasPermissionForTenant($tenant, 'metadata.suggestions.dismiss')) {
            return response()->json([
                'message' => 'You do not have permission to reject suggestions.',
            ], 403);
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

        // Record rejection activity event (same as dismissed)
        try {
            // Get field info for better timeline display
            $fieldKey = null;
            $fieldLabel = null;
            $fieldId = $suggestion->metadata_field_id ?? null;
            if ($fieldId) {
                $field = DB::table('metadata_fields')
                    ->where('id', $fieldId)
                    ->where('tenant_id', $tenant->id)
                    ->first();
                if ($field) {
                    $fieldKey = $field->key;
                    $fieldLabel = $field->label ?? $field->name ?? $fieldKey;
                }
            }

            ActivityRecorder::record(
                tenant: $tenant,
                eventType: EventType::ASSET_AI_SUGGESTION_DISMISSED,
                subject: $asset,
                actor: $user,
                brand: $brand,
                metadata: [
                    'field_key' => $fieldKey,
                    'field_label' => $fieldLabel,
                    'suggestion_id' => $suggestionId,
                ]
            );
        } catch (\Exception $e) {
            // Activity logging must never break processing
            Log::error('Failed to log AI suggestion rejection activity', [
                'asset_id' => $asset->id,
                'suggestion_id' => $suggestionId,
                'error' => $e->getMessage(),
            ]);
        }

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
     * Note: asset->type is organizational (asset/deliverable/ai_generated),
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

        // Load current metadata values
        // CRITICAL: Automatic/system fields (population_mode = 'automatic') do NOT require approval
        // They are authoritative the moment they exist and should always be included if present
        // Only AI fields require approved_at
        
        // Get automatic field IDs (fields with population_mode = 'automatic')
        // Query metadata_fields table to find all automatic fields
        $automaticFieldIds = DB::table('metadata_fields')
            ->where('population_mode', 'automatic')
            ->pluck('id')
            ->toArray();
        
        // Check if user can approve (to show pending metadata)
        $canApprove = $this->approvalResolver->canApprove($user, $tenant);
        
        // Build query: Include automatic fields regardless of approved_at, require approved_at for others
        // Phase M-1: Include 'system' source for system-computed metadata (orientation, color_space, resolution_class)
        // IMPORTANT: 'system' metadata is automatic and always included; exclusion here causes silent UI loss
        // This prevents someone from "optimizing" it away later.
        // For users who can approve, also include pending metadata (approved_at IS NULL) so they can see what needs approval
        $currentMetadataRows = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->whereIn('asset_metadata.source', ['user', 'automatic', 'ai', 'manual_override', 'system'])
            ->whereNotIn('asset_metadata.source', ['user_rejected', 'ai_rejected'])
            ->where(function($query) use ($automaticFieldIds, $canApprove) {
                // Automatic fields: include if value exists (no approval required)
                if (!empty($automaticFieldIds)) {
                    $query->whereIn('asset_metadata.metadata_field_id', $automaticFieldIds)
                          ->orWhere(function($q) use ($automaticFieldIds, $canApprove) {
                              // Non-automatic fields: require approval OR show pending if user can approve
                              $q->whereNotIn('asset_metadata.metadata_field_id', $automaticFieldIds);
                              if ($canApprove) {
                                  // Approvers can see both approved and pending metadata
                                  $q->where(function($subQ) {
                                      $subQ->whereNotNull('asset_metadata.approved_at')
                                           ->orWhereNull('asset_metadata.approved_at');
                                  });
                              } else {
                                  // Non-approvers only see approved metadata
                                  $q->whereNotNull('asset_metadata.approved_at');
                              }
                          });
                } else {
                    // No automatic fields
                    if ($canApprove) {
                        // Approvers can see both approved and pending metadata
                        $query->where(function($q) {
                            $q->whereNotNull('asset_metadata.approved_at')
                              ->orWhereNull('asset_metadata.approved_at');
                        });
                    } else {
                        // Non-approvers only see approved metadata
                        $query->whereNotNull('asset_metadata.approved_at');
                    }
                }
            })
            ->select(
                'metadata_fields.id as metadata_field_id',
                'metadata_fields.key',
                'metadata_fields.type',
                'metadata_fields.population_mode',
                'asset_metadata.value_json',
                'asset_metadata.source',
                'asset_metadata.confidence',
                'asset_metadata.overridden_at',
                'asset_metadata.overridden_by',
                'asset_metadata.approved_at'
            )
            ->orderByRaw("
                CASE 
                    WHEN asset_metadata.approved_at IS NOT NULL THEN 1
                    ELSE 2
                END
            ")
            ->orderByRaw("
                CASE 
                    WHEN asset_metadata.source = 'manual_override' THEN 1
                    WHEN asset_metadata.source = 'user' THEN 2
                    WHEN asset_metadata.source = 'automatic' THEN 3
                    WHEN asset_metadata.source = 'ai' THEN 4
                    ELSE 5
                END
            ")
            ->orderByRaw("
                CASE 
                    WHEN asset_metadata.approved_at IS NOT NULL THEN asset_metadata.approved_at
                    ELSE asset_metadata.created_at
                END DESC
            ")
            ->get()
            ->groupBy('metadata_field_id');

        // Build map of field_id to current values and override state
        // Suppress low-confidence AI metadata values at read time
        $fieldValues = [];
        $fieldOverrideState = []; // Phase B5: Track override state for hybrid fields
        foreach ($currentMetadataRows as $fieldId => $rows) {
            // Get field type and most recent row (highest priority after ordering)
            // For approvers, prefer approved values, but show pending if no approved exists
            $approvedRow = $rows->firstWhere('approved_at', '!=', null);
            $mostRecent = $approvedRow ?? $rows->first();
            $fieldKey = $mostRecent->key ?? null;
            $fieldType = $mostRecent->type ?? 'text';
            $source = $mostRecent->source ?? null;
            $confidence = $mostRecent->confidence !== null ? (float) $mostRecent->confidence : null;
            $populationMode = $mostRecent->population_mode ?? 'manual';
            $isPending = $mostRecent->approved_at === null;

            // CRITICAL: Confidence suppression applies ONLY to AI fields
            // Automatic/system fields are never suppressed (they are authoritative)
            $isAutomaticField = $populationMode === 'automatic';
            $isAiField = $populationMode === 'ai';
            
            if ($fieldKey && $isAiField && $this->confidenceService->shouldSuppress($fieldKey, $confidence)) {
                // Suppress low-confidence AI metadata values (PRESENTATION LAYER ONLY)
                // Treat as if value doesn't exist - skip this field entirely
                continue;
            }
            

            if ($fieldType === 'multiselect') {
                // For multiselect, collect all unique values from all rows
                // Filter out low-confidence values for each row (ONLY for AI fields)
                $allValues = [];
                foreach ($rows as $row) {
                    $rowConfidence = $row->confidence !== null ? (float) $row->confidence : null;
                    $rowFieldKey = $row->key ?? $fieldKey;
                    $rowPopulationMode = $row->population_mode ?? 'manual';
                    
                    // Skip low-confidence values in multiselect arrays (ONLY for AI fields)
                    // Automatic/system fields are never suppressed
                    if ($rowFieldKey && $rowPopulationMode === 'ai' && $this->confidenceService->shouldSuppress($rowFieldKey, $rowConfidence)) {
                        continue;
                    }
                    
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
                // If approver and there's a pending value, show it even if there's no approved value
                $fieldValues[$fieldId] = json_decode($mostRecent->value_json, true);
            }

            // Phase B5: Track override state
            $fieldOverrideState[$fieldId] = [
                'source' => $source,
                'is_overridden' => $source === 'manual_override',
                'overridden_at' => $mostRecent->overridden_at ?? null,
                'overridden_by' => $mostRecent->overridden_by ?? null,
                'is_pending' => $isPending, // Track if this value is pending approval
            ];
        }

        // Phase 8: Check for pending metadata per field
        // Include both AI fields and user-added metadata that requires approval
        // Automatic/system fields never require approval and must be excluded
        $pendingMetadata = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->whereNull('asset_metadata.approved_at')
            ->whereNotIn('asset_metadata.source', ['user_rejected', 'ai_rejected', 'automatic', 'system', 'manual_override'])
            ->whereIn('asset_metadata.source', ['ai', 'user']) // Both AI and user-added metadata can require approval
            ->where('metadata_fields.population_mode', '!=', 'automatic') // Exclude automatic fields
            ->select('asset_metadata.metadata_field_id', 'asset_metadata.id as pending_metadata_id')
            ->get();
        
        $pendingFieldIds = $pendingMetadata->pluck('metadata_field_id')->unique()->toArray();
        $pendingMetadataByField = $pendingMetadata->groupBy('metadata_field_id')->map(function ($items) {
            return $items->pluck('pending_metadata_id')->toArray();
        })->toArray();

        // Phase C2/C4: Get visibility resolver for category suppression and tenant override filtering
        $visibilityResolver = app(\App\Services\MetadataVisibilityResolver::class);
        
        // Get tenant for tenant-level overrides
        $tenant = $asset->tenant;

        // Filter to editable fields only
        $candidateFields = [];
        foreach ($schema['fields'] ?? [] as $field) {
            // Exclude internal-only fields, EXCEPT quality_rating which should be visible
            $fieldKey = $field['key'] ?? null;
            if (($field['is_internal_only'] ?? false) && $fieldKey !== 'quality_rating') {
                continue;
            }

            // Exclude dimensions - it's file info, not metadata, and should only appear in file info area
            // Dimensions is still used for orientation calculation but shouldn't be shown in metadata section
            if ($fieldKey === 'dimensions') {
                continue;
            }

            $candidateFields[] = $field;
        }

        // Phase C2/C4: Apply category suppression and tenant override filtering via centralized resolver
        $visibleFields = $visibilityResolver->filterVisibleFields($candidateFields, $category, $tenant);

        // Continue with permission and other checks on visible fields
        $editableFields = [];
        foreach ($visibleFields as $field) {

            // Load field definition from database (not in resolved schema)
            $fieldDef = DB::table('metadata_fields')
                ->where('id', $field['field_id'])
                ->first();

            if (!$fieldDef) {
                continue;
            }

            // Phase B3: Exclude fields that should not appear in Quick View (drawer and details modal)
            // For automatic/readonly fields, respect show_on_edit setting (can be toggled in UI)
            // show_on_edit controls visibility in both AssetDrawer and AssetDetailsModal
            $showOnEdit = $field['show_on_edit'] ?? true;
            $populationMode = $field['population_mode'] ?? 'manual';
            $isReadonly = ($field['readonly'] ?? false) || ($populationMode === 'automatic');
            $isUserEditable = $fieldDef->is_user_editable ?? true;
            
            // Skip fields if show_on_edit is false (applies to both regular and automatic fields)
            // Users can toggle show_on_edit for automatic fields via the metadata management UI
            if (!$showOnEdit) {
                continue; // Hidden from edit/drawer UI
            }

            // For non-readonly fields, they must be user-editable
            // For readonly/automatic fields, we allow them to show (read-only display) even if not user-editable
            if (!$isReadonly && !$isUserEditable) {
                continue; // Non-readonly fields must be user-editable
            }

            // Phase 4: Check edit permission
            // For readonly/automatic fields, we still want to show them (read-only display)
            // So we only check permission for non-readonly fields
            $canEdit = true; // Default for readonly/automatic fields (they're shown read-only)
            if (!$isReadonly) {
                $canEdit = $this->permissionResolver->canEdit(
                    $field['field_id'],
                    $userRole,
                    $tenant->id,
                    $brand->id,
                    $category->id
                );

                // For rating fields, show them even if user can't edit (so they can see the rating)
                // For other fields, skip if user cannot edit
                $isRating = ($field['type'] ?? 'text') === 'rating' || ($field['key'] ?? null) === 'quality_rating';
                if (!$canEdit && !$isRating) {
                    continue; // Skip fields user cannot edit (except rating fields)
                }
            }

            // Get current value(s) for this field
            // For approvers, show pending values if no approved value exists
            $currentValue = $fieldValues[$field['field_id']] ?? null;
            $fieldOverrideInfo = $fieldOverrideState[$field['field_id']] ?? [];
            $isValuePending = $fieldOverrideInfo['is_pending'] ?? false;

            // Resolve display label: prefer display_label from schema, fall back to system_label from DB, then key
            $displayLabel = $field['display_label'] 
                ?? $fieldDef->system_label 
                ?? $field['key'];

            // Get pending metadata IDs for this field
            $pendingMetadataIds = $pendingMetadataByField[$field['field_id']] ?? [];
            
            $editableFields[] = [
                'metadata_field_id' => $field['field_id'],
                'field_key' => $field['key'],
                'key' => $field['key'], // Also include as 'key' for consistency
                'display_label' => $displayLabel,
                'type' => $field['type'],
                'options' => $field['options'] ?? [],
                'is_user_editable' => !$isReadonly && $canEdit, // Only editable if not readonly AND has permission
                'can_edit' => !$isReadonly && $canEdit, // Reflect actual permission check result
                'current_value' => $currentValue,
                'has_pending' => in_array($field['field_id'], $pendingFieldIds), // Phase 8
                'pending_metadata_ids' => $pendingMetadataIds, // IDs of pending metadata records for this field
                'is_value_pending' => $isValuePending, // Whether the current displayed value is pending approval
                // Phase B2: Add readonly and population_mode flags
                'readonly' => $isReadonly,
                'population_mode' => $populationMode,
            ];
        }


        // Determine if approval is required and if user can approve
        $approvalRequired = $this->approvalResolver->isApprovalEnabledForBrand($tenant, $brand);
        $approverCapable = $this->approvalResolver->canApprove($user, $tenant);

        return response()->json([
            'fields' => $editableFields,
            'approval_required' => $approvalRequired,
            'approver_capable' => $approverCapable,
        ]);
    }

    /**
     * Get all metadata fields for an asset (including read-only and automatic fields).
     * Used for testing/verification purposes.
     *
     * GET /assets/{asset}/metadata/all
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function getAllMetadata(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }
        
        // Check if user can approve (to show pending metadata)
        $canApprove = $this->approvalResolver->canApprove($user, $tenant);

        // Load category for schema resolution
        $category = null;
        if ($asset->metadata && isset($asset->metadata['category_id'])) {
            $categoryId = $asset->metadata['category_id'];
            $category = \App\Models\Category::where('id', $categoryId)
                ->where('tenant_id', $asset->tenant_id)
                ->first();
        }

        if (!$category) {
            return response()->json([
                'category' => null,
                'fields' => [],
            ]);
        }

        // Determine asset type
        $assetType = $this->determineAssetType($asset);

        // AssetDetailsModal is a "source of truth" - shows ALL fields regardless of Quick View setting
        // Only respects category enablement (big blue toggle), not show_on_edit checkbox
        // We need to bypass the schema resolver's is_visible filter (which includes Quick View)
        // and only check category-level enablement
        
        // Load fields directly and resolve manually, checking only category enablement
        $fields = DB::table('metadata_fields')
            ->where(function ($query) use ($assetType) {
                $query->where('applies_to', $assetType)
                    ->orWhere('applies_to', 'all');
            })
            ->whereNull('deprecated_at')
            ->where(function ($query) use ($asset) {
                $query->where(function ($q) {
                    $q->where('scope', 'system')
                        ->whereNull('tenant_id');
                })
                ->orWhere(function ($q) use ($asset) {
                    $q->where('scope', 'tenant')
                        ->where('tenant_id', $asset->tenant_id)
                        ->where('is_active', true);
                });
            })
            ->select([
                'id',
                'key',
                'system_label',
                'type',
                'applies_to',
                'scope',
                'is_filterable',
                'is_user_editable',
                'is_ai_trainable',
                'is_upload_visible',
                'is_internal_only',
                'group_key',
                'plan_gate',
                'deprecated_at',
                'replacement_field_id',
                DB::raw("COALESCE(population_mode, 'manual') as population_mode"),
                DB::raw("COALESCE(show_on_upload, true) as show_on_upload"),
                DB::raw("COALESCE(show_on_edit, true) as show_on_edit"),
                DB::raw("COALESCE(show_in_filters, true) as show_in_filters"),
                DB::raw("COALESCE(readonly, false) as readonly"),
                DB::raw("COALESCE(is_primary, false) as is_primary"),
            ])
            ->get()
            ->keyBy('id');
        
        // Check category enablement only (big blue toggle) - ignore tenant-level Quick View
        $systemVisibilityService = app(\App\Services\SystemMetadataVisibilityService::class);
        $systemCategoryId = $category->system_category_id;
        
        // Load option visibility for select/multiselect fields
        $optionVisibility = [];
        $optionRows = DB::table('metadata_option_visibility')
            ->where('tenant_id', $asset->tenant_id)
            ->where(function ($q) use ($asset, $category) {
                $q->where(function ($subQ) {
                    $subQ->whereNull('brand_id')->whereNull('category_id');
                });
                if ($asset->brand_id) {
                    $q->orWhere(function ($subQ) use ($asset) {
                        $subQ->where('brand_id', $asset->brand_id)->whereNull('category_id');
                    });
                }
                if ($asset->brand_id && $category->id) {
                    $q->orWhere(function ($subQ) use ($asset, $category) {
                        $subQ->where('brand_id', $asset->brand_id)->where('category_id', $category->id);
                    });
                }
            })
            ->get();
        
        foreach ($optionRows as $row) {
            if (!isset($optionVisibility[$row->metadata_option_id])) {
                $optionVisibility[$row->metadata_option_id] = (bool) $row->is_hidden;
            }
        }
        
        // Resolve fields, checking only category enablement (not Quick View)
        // Check category-level visibility overrides directly (big blue toggle)
        // This bypasses tenant-level Quick View settings
        $categoryVisibilityOverrides = DB::table('metadata_field_visibility')
            ->where('tenant_id', $asset->tenant_id)
            ->where('brand_id', $asset->brand_id)
            ->where('category_id', $category->id)
            ->whereIn('metadata_field_id', $fields->keys()->toArray())
            ->pluck('is_hidden', 'metadata_field_id')
            ->toArray();
        
        $schemaFields = [];
        foreach ($fields as $fieldId => $field) {
            // Check if field is enabled for this category (big blue toggle)
            // 1. Check category-level visibility override (highest priority - the big blue toggle)
            if (isset($categoryVisibilityOverrides[$fieldId])) {
                if ($categoryVisibilityOverrides[$fieldId]) {
                    continue; // Field disabled for this category (big blue toggle OFF)
                }
                // Category override says visible - include it (regardless of Quick View)
            } else {
                // No category override - check system-level category suppression
                if ($systemCategoryId !== null) {
                    $isCategoryEnabled = $systemVisibilityService->isVisibleForCategory(
                        $fieldId,
                        $systemCategoryId
                    );
                    
                    if (!$isCategoryEnabled) {
                        continue; // Field disabled for this category (big blue toggle OFF)
                    }
                }
            }
            
            // Resolve field options
            $options = [];
            if (in_array($field->type, ['select', 'multiselect'], true)) {
                $optionRows = DB::table('metadata_options')
                    ->where('metadata_field_id', $fieldId)
                    ->orderBy('system_label')
                    ->get();
                
                foreach ($optionRows as $option) {
                    $isHidden = $optionVisibility[$option->id] ?? false;
                    if (!$isHidden) {
                        $options[] = [
                            'value' => $option->value,
                            'display_label' => $option->system_label ?? $option->value,
                        ];
                    }
                }
            }
            
            // Resolve display label
            $displayLabel = $field->system_label ?? $field->key;
            
            // Include field in modal (regardless of Quick View setting)
            $schemaFields[] = [
                'field_id' => $fieldId,
                'key' => $field->key,
                'display_label' => $displayLabel,
                'type' => $field->type,
                'group_key' => $field->group_key,
                'applies_to' => $field->applies_to,
                'is_visible' => true, // Always visible in modal (source of truth)
                'is_upload_visible' => (bool) $field->is_upload_visible,
                'is_filterable' => (bool) $field->is_filterable,
                'is_internal_only' => (bool) $field->is_internal_only,
                'population_mode' => $field->population_mode ?? 'manual',
                'show_on_upload' => (bool) ($field->show_on_upload ?? true),
                'show_on_edit' => (bool) ($field->show_on_edit ?? true), // For reference only, not used for filtering
                'show_in_filters' => (bool) ($field->show_in_filters ?? true),
                'readonly' => (bool) ($field->readonly ?? false),
                'is_primary' => false, // Not relevant for modal
                'options' => $options,
            ];
        }
        
        $schema = ['fields' => $schemaFields];

        // Load all current metadata values from asset_metadata table
        // CRITICAL: Automatic/system fields (population_mode = 'automatic') do NOT require approval
        // They are authoritative the moment they exist and should always be included if present
        // Only AI fields require approved_at
        
        // Get automatic field IDs (fields with population_mode = 'automatic')
        $automaticFieldIds = DB::table('metadata_fields')
            ->where('population_mode', 'automatic')
            ->pluck('id')
            ->toArray();
        
        // Build query: Include automatic fields regardless of approved_at, require approved_at for others
        // Phase M-1: Include 'system' source for system-computed metadata (orientation, color_space, resolution_class)
        // IMPORTANT: 'system' metadata is automatic and always included; exclusion here causes silent UI loss
        // This prevents someone from "optimizing" it away later.
        // For users who can approve, also include pending metadata (approved_at IS NULL) so they can see what needs approval
        $currentMetadataRows = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->whereNotIn('asset_metadata.source', ['user_rejected', 'ai_rejected'])
            ->where(function($query) use ($automaticFieldIds, $canApprove) {
                // Automatic fields: include if value exists (no approval required)
                if (!empty($automaticFieldIds)) {
                    $query->whereIn('asset_metadata.metadata_field_id', $automaticFieldIds)
                          ->orWhere(function($q) use ($automaticFieldIds, $canApprove) {
                              // Non-automatic fields: require approval OR show pending if user can approve
                              $q->whereNotIn('asset_metadata.metadata_field_id', $automaticFieldIds);
                              if ($canApprove) {
                                  // Approvers can see both approved and pending metadata
                                  $q->where(function($subQ) {
                                      $subQ->whereNotNull('asset_metadata.approved_at')
                                           ->orWhereNull('asset_metadata.approved_at');
                                  });
                              } else {
                                  // Non-approvers only see approved metadata
                                  $q->whereNotNull('asset_metadata.approved_at');
                              }
                          });
                } else {
                    // No automatic fields
                    if ($canApprove) {
                        // Approvers can see both approved and pending metadata
                        $query->where(function($q) {
                            $q->whereNotNull('asset_metadata.approved_at')
                              ->orWhereNull('asset_metadata.approved_at');
                        });
                    } else {
                        // Non-approvers only see approved metadata
                        $query->whereNotNull('asset_metadata.approved_at');
                    }
                }
            })
            ->select(
                'metadata_fields.id as metadata_field_id',
                'metadata_fields.key',
                'metadata_fields.type',
                'metadata_fields.population_mode',
                'asset_metadata.value_json',
                'asset_metadata.source',
                'asset_metadata.producer',
                'asset_metadata.confidence',
                'asset_metadata.overridden_at',
                'asset_metadata.overridden_by',
                'asset_metadata.approved_at',
                'asset_metadata.approved_by'
            )
            ->orderByRaw("
                CASE 
                    WHEN asset_metadata.approved_at IS NOT NULL THEN 1
                    ELSE 2
                END
            ")
            ->orderByRaw("
                CASE 
                    WHEN asset_metadata.source = 'manual_override' THEN 1
                    WHEN asset_metadata.source = 'user' THEN 2
                    WHEN asset_metadata.source = 'automatic' THEN 3
                    WHEN asset_metadata.source = 'system' THEN 4
                    WHEN asset_metadata.source = 'ai' THEN 5
                    ELSE 6
                END
            ")
            ->orderByRaw("
                CASE 
                    WHEN asset_metadata.approved_at IS NOT NULL THEN asset_metadata.approved_at
                    ELSE asset_metadata.created_at
                END DESC
            ")
            ->get()
            ->groupBy('metadata_field_id');

        // Phase 8: Check for pending metadata per field
        // Include both AI fields and user-added metadata that requires approval
        // Automatic/system fields never require approval and must be excluded
        $pendingMetadata = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->whereNull('asset_metadata.approved_at')
            ->whereNotIn('asset_metadata.source', ['user_rejected', 'ai_rejected', 'automatic', 'system', 'manual_override'])
            ->whereIn('asset_metadata.source', ['ai', 'user']) // Both AI and user-added metadata can require approval
            ->where('metadata_fields.population_mode', '!=', 'automatic') // Exclude automatic fields
            ->select('asset_metadata.metadata_field_id', 'asset_metadata.id as pending_metadata_id')
            ->get();
        
        $pendingFieldIds = $pendingMetadata->pluck('metadata_field_id')->unique()->toArray();
        $pendingMetadataByField = $pendingMetadata->groupBy('metadata_field_id')->map(function ($items) {
            return $items->pluck('pending_metadata_id')->toArray();
        })->toArray();

        // Build map of field_id to current values and metadata
        $fieldValues = [];
        $fieldMetadata = [];
        foreach ($currentMetadataRows as $fieldId => $rows) {
            // For approvers, prefer approved values, but show pending if no approved exists
            $approvedRow = $rows->firstWhere('approved_at', '!=', null);
            $mostRecent = $approvedRow ?? $rows->first();
            $fieldType = $mostRecent->type ?? 'text';

            if ($fieldType === 'multiselect') {
                // For multiselect, collect all unique values
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
                // If approver and there's a pending value, show it even if there's no approved value
                $fieldValues[$fieldId] = json_decode($mostRecent->value_json, true);
            }

            $isPending = $mostRecent->approved_at === null;

            $fieldMetadata[$fieldId] = [
                'source' => $mostRecent->source,
                'producer' => $mostRecent->producer,
                'confidence' => $mostRecent->confidence,
                'is_overridden' => $mostRecent->source === 'manual_override',
                'overridden_at' => $mostRecent->overridden_at,
                'overridden_by' => $mostRecent->overridden_by,
                'approved_at' => $mostRecent->approved_at,
                'approved_by' => $mostRecent->approved_by,
                'is_pending' => $isPending, // Track if this value is pending approval
            ];
        }

        // Build response with all fields from schema
        // AssetDetailsModal is a "source of truth" - shows ALL fields regardless of Quick View setting
        // Only respects category enablement (the big blue toggle), not show_on_edit checkbox
        // This allows users to see all metadata in the details modal even if hidden from drawer
        $allFields = [];
        foreach ($schema['fields'] ?? [] as $field) {
            // Don't filter by show_on_edit here - modal shows everything
            // Category filtering is already handled by MetadataSchemaResolver (is_visible check)

            $fieldId = $field['field_id'];
            $currentValue = $fieldValues[$fieldId] ?? null;
            $metadata = $fieldMetadata[$fieldId] ?? null;

            // Get pending metadata IDs for this field
            $pendingMetadataIds = $pendingMetadataByField[$fieldId] ?? [];
            $isValuePending = $metadata && ($metadata['is_pending'] ?? false);
            
            $allFields[] = [
                'metadata_field_id' => $fieldId,
                'field_key' => $field['key'],
                'key' => $field['key'], // Also include as 'key' for consistency
                'display_label' => $field['display_label'] ?? $field['key'],
                'type' => $field['type'],
                'options' => $field['options'] ?? [],
                'population_mode' => $field['population_mode'] ?? 'manual',
                'readonly' => $field['readonly'] ?? false,
                'is_ai_related' => $field['is_ai_related'] ?? false,
                'is_system_generated' => ($field['population_mode'] ?? 'manual') === 'automatic',
                'current_value' => $currentValue,
                'has_value' => $currentValue !== null,
                'has_pending' => in_array($fieldId, $pendingFieldIds),
                'pending_metadata_ids' => $pendingMetadataIds,
                'is_value_pending' => $isValuePending,
                'metadata' => $metadata,
            ];
        }

        return response()->json([
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
            ],
            'fields' => $allFields,
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

        // Check if field is internal-only (allow quality_rating to be edited)
        $fieldKey = $fieldDef['key'] ?? null;
        if (($fieldDef['is_internal_only'] ?? false) && $fieldKey !== 'quality_rating') {
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

        // Phase 8: Check if approval is required (unless user has bypass_approval permission)
        // Phase M-2: Pass brand for company + brand level gating
        $requiresApproval = $this->approvalResolver->requiresApproval('user', $tenant, $user, $brand);

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
        // Note: category->asset_type is organizational (asset/deliverable/ai_generated),
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

        // Phase C2/C4: Pass category and tenant models for suppression check (via MetadataVisibilityResolver)
        $tenant = app('tenant');
        $filterService = app(\App\Services\MetadataFilterService::class);
        $filterableFields = $filterService->getFilterableFields($schema, $category, $tenant);

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
        // Include both AI fields and user-added metadata that requires approval
        // Automatic/system fields never require approval and must be excluded
        $pendingMetadata = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->whereNull('asset_metadata.approved_at')
            ->whereNotIn('asset_metadata.source', ['user_rejected', 'ai_rejected', 'automatic', 'system', 'manual_override'])
            ->whereIn('asset_metadata.source', ['ai', 'user']) // Both AI and user-added metadata can require approval
            ->where('metadata_fields.population_mode', '!=', 'automatic') // Exclude automatic fields
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
        $canApprove = $this->approvalResolver->canApprove($user, $tenant);

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
        if (!$this->approvalResolver->canApprove($user, $tenant)) {
            return response()->json([
                'message' => 'You do not have permission to approve metadata',
            ], 403);
        }

        // Get asset and field info for activity logging
        $asset = Asset::findOrFail($metadata->asset_id);
        $field = DB::table('metadata_fields')->where('id', $metadata->metadata_field_id)->first();
        $fieldKey = $field->key ?? 'unknown';
        $fieldLabel = $field->system_label ?? $fieldKey;
        $tenant = app('tenant');
        $brand = app('brand');

        DB::transaction(function () use ($metadata, $metadataId, $user, $asset, $fieldKey, $fieldLabel, $tenant, $brand) {
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

            // Log activity: User approved metadata
            try {
                ActivityRecorder::record(
                    tenant: $tenant,
                    eventType: EventType::ASSET_METADATA_UPDATED,
                    subject: $asset,
                    actor: $user,
                    brand: $brand,
                    metadata: [
                        'field_key' => $fieldKey,
                        'field_label' => $fieldLabel,
                        'field_id' => $metadata->metadata_field_id,
                        'action' => 'approved',
                        'value' => $metadata->value_json,
                        'previous_source' => $metadata->source,
                    ]
                );
            } catch (\Exception $e) {
                // Activity logging must never break processing
                Log::error('Failed to log metadata approval activity', [
                    'asset_id' => $asset->id,
                    'metadata_id' => $metadataId,
                    'error' => $e->getMessage(),
                ]);
            }
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
        if (!$this->approvalResolver->canApprove($user, $tenant)) {
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
        if (!$this->approvalResolver->canApprove($user, $tenant)) {
            return response()->json([
                'message' => 'You do not have permission to reject metadata',
            ], 403);
        }

        // Get field info for activity logging
        $field = DB::table('metadata_fields')->where('id', $metadata->metadata_field_id)->first();
        $fieldKey = $field->key ?? 'unknown';
        $fieldLabel = $field->system_label ?? $fieldKey;

        DB::transaction(function () use ($metadata, $metadataId, $user, $asset, $fieldKey, $fieldLabel, $tenant, $brand) {
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

            // Log activity: User rejected metadata
            try {
                ActivityRecorder::record(
                    tenant: $tenant,
                    eventType: EventType::ASSET_METADATA_UPDATED,
                    subject: $asset,
                    actor: $user,
                    brand: $brand,
                    metadata: [
                        'field_key' => $fieldKey,
                        'field_label' => $fieldLabel,
                        'field_id' => $metadata->metadata_field_id,
                        'action' => 'rejected',
                        'rejected_value' => $metadata->value_json,
                        'previous_source' => $metadata->source,
                    ]
                );
            } catch (\Exception $e) {
                // Activity logging must never break processing
                Log::error('Failed to log metadata rejection activity', [
                    'asset_id' => $asset->id,
                    'metadata_id' => $metadataId,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return response()->json(['message' => 'Metadata rejected']);
    }

    /**
     * Get reviewable metadata candidates for an asset.
     *
     * GET /assets/{asset}/metadata/review
     *
     * Phase B9: Returns reviewable candidates that need human review.
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function getReview(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Check if user can view metadata suggestions
        if (!$user->hasPermissionForTenant($tenant, 'metadata.suggestions.view')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $reviewService = app(\App\Services\MetadataReviewService::class);
        $reviewItems = $reviewService->getReviewableCandidates($asset);

        return response()->json([
            'asset_id' => $asset->id,
            'asset_title' => $asset->title,
            'review_items' => $reviewItems,
        ]);
    }

    /**
     * Approve a metadata candidate (accepts AI suggestion).
     *
     * POST /metadata/candidates/{candidateId}/approve
     *
     * Phase B9: Accepts an AI suggestion by preserving original AI attribution.
     * Maintains source = 'ai', original confidence, producer = 'ai'.
     * This is NOT a manual override - it's accepting an AI recommendation.
     *
     * @param int $candidateId
     * @return JsonResponse
     */
    public function approveCandidate(int $candidateId): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Load candidate
        $candidate = DB::table('asset_metadata_candidates')
            ->where('id', $candidateId)
            ->first();

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found'], 404);
        }

        // Load asset to verify tenant/brand
        $asset = Asset::find($candidate->asset_id);
        if (!$asset || $asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Verify candidate is not already resolved or dismissed
        if ($candidate->resolved_at) {
            return response()->json(['message' => 'Candidate is already resolved'], 422);
        }

        if ($candidate->dismissed_at) {
            return response()->json(['message' => 'Candidate is already dismissed'], 422);
        }

        // Check if manual override already exists
        $existingOverride = DB::table('asset_metadata')
            ->where('asset_id', $asset->id)
            ->where('metadata_field_id', $candidate->metadata_field_id)
            ->where('source', 'manual_override')
            ->whereNotNull('approved_at')
            ->first();

        if ($existingOverride) {
            return response()->json(['message' => 'Manual override already exists for this field'], 422);
        }

        // Get field info for activity logging
        $field = DB::table('metadata_fields')->where('id', $candidate->metadata_field_id)->first();
        $fieldKey = $field->key ?? 'unknown';
        $fieldLabel = $field->system_label ?? $fieldKey;

        DB::transaction(function () use ($asset, $candidate, $user, $candidateId, $fieldKey, $fieldLabel, $tenant, $brand) {
            // Accept AI suggestion by preserving AI attribution 
            // This is NOT a manual override - it's accepting an AI recommendation
            DB::table('asset_metadata')->insert([
                'asset_id' => $asset->id,
                'metadata_field_id' => $candidate->metadata_field_id,
                'value_json' => $candidate->value_json,
                'source' => $candidate->source, // Preserve original source (typically 'ai')
                'confidence' => $candidate->confidence, // Preserve original AI confidence
                'producer' => $candidate->producer, // Preserve original producer (typically 'ai') 
                'approved_at' => now(),
                'approved_by' => $user->id,
                'overridden_at' => null, // Not an override - it's an acceptance of AI suggestion
                'overridden_by' => null, // Not an override - it's an acceptance of AI suggestion
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Mark candidate as resolved
            DB::table('asset_metadata_candidates')
                ->where('id', $candidateId)
                ->update(['resolved_at' => now()]);

            // Log activity: User approved metadata candidate
            try {
                ActivityRecorder::record(
                    tenant: $tenant,
                    eventType: EventType::ASSET_METADATA_UPDATED,
                    subject: $asset,
                    actor: $user,
                    brand: $brand,
                    metadata: [
                        'field_key' => $fieldKey,
                        'field_label' => $fieldLabel,
                        'field_id' => $candidate->metadata_field_id,
                        'action' => 'candidate_approved',
                        'value' => $candidate->value_json,
                        'candidate_id' => $candidateId,
                        'candidate_producer' => $candidate->producer,
                        'candidate_confidence' => $candidate->confidence,
                    ]
                );
            } catch (\Exception $e) {
                // Activity logging must never break processing
                Log::error('Failed to log candidate approval activity', [
                    'asset_id' => $asset->id,
                    'candidate_id' => $candidateId,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        Log::info('[AssetMetadataController] Candidate approved', [
            'asset_id' => $asset->id,
            'candidate_id' => $candidateId,
            'metadata_field_id' => $candidate->metadata_field_id,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Candidate approved and created as manual override',
        ]);
    }

    /**
     * Reject a metadata candidate (marks as dismissed).
     *
     * POST /metadata/candidates/{candidateId}/reject
     *
     * Phase B9: Rejects a candidate by marking it as dismissed.
     * Preserves candidate for audit history but excludes it from future resolution.
     *
     * @param int $candidateId
     * @return JsonResponse
     */
    public function rejectCandidate(int $candidateId): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Load candidate
        $candidate = DB::table('asset_metadata_candidates')
            ->where('id', $candidateId)
            ->first();

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found'], 404);
        }

        // Load asset to verify tenant/brand
        $asset = Asset::find($candidate->asset_id);
        if (!$asset || $asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Verify candidate is not already resolved or dismissed
        if ($candidate->resolved_at) {
            return response()->json(['message' => 'Candidate is already resolved'], 422);
        }

        if ($candidate->dismissed_at) {
            return response()->json(['message' => 'Candidate is already dismissed'], 422);
        }

        // Get field info for activity logging
        $field = DB::table('metadata_fields')->where('id', $candidate->metadata_field_id)->first();
        $fieldKey = $field->key ?? 'unknown';
        $fieldLabel = $field->system_label ?? $fieldKey;

        // Mark candidate as dismissed
        DB::table('asset_metadata_candidates')
            ->where('id', $candidateId)
            ->update(['dismissed_at' => now()]);

        // Log activity: User rejected metadata candidate
        try {
            ActivityRecorder::record(
                tenant: $tenant,
                eventType: EventType::ASSET_METADATA_UPDATED,
                subject: $asset,
                actor: $user,
                brand: $brand,
                metadata: [
                    'field_key' => $fieldKey,
                    'field_label' => $fieldLabel,
                    'field_id' => $candidate->metadata_field_id,
                    'action' => 'candidate_rejected',
                    'rejected_value' => $candidate->value_json,
                    'candidate_id' => $candidateId,
                    'candidate_producer' => $candidate->producer,
                    'candidate_confidence' => $candidate->confidence,
                ]
            );
        } catch (\Exception $e) {
            // Activity logging must never break processing
            Log::error('Failed to log candidate rejection activity', [
                'asset_id' => $asset->id,
                'candidate_id' => $candidateId,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('[AssetMetadataController] Candidate rejected', [
            'asset_id' => $asset->id,
            'candidate_id' => $candidateId,
            'metadata_field_id' => $candidate->metadata_field_id,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Candidate rejected and dismissed',
        ]);
    }

    /**
     * Defer a metadata candidate (no change).
     *
     * POST /metadata/candidates/{candidateId}/defer
     *
     * Phase B9: Defers review of a candidate without making any changes.
     * This is a no-op action for tracking purposes only.
     *
     * @param int $candidateId
     * @return JsonResponse
     */
    public function deferCandidate(int $candidateId): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Load candidate
        $candidate = DB::table('asset_metadata_candidates')
            ->where('id', $candidateId)
            ->first();

        if (!$candidate) {
            return response()->json(['message' => 'Candidate not found'], 404);
        }

        // Load asset to verify tenant/brand
        $asset = Asset::find($candidate->asset_id);
        if (!$asset || $asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Log deferral (no database changes)
        Log::info('[AssetMetadataController] Candidate deferred', [
            'asset_id' => $asset->id,
            'candidate_id' => $candidateId,
            'metadata_field_id' => $candidate->metadata_field_id,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Candidate review deferred',
        ]);
    }

    /**
     * Get AI metadata suggestions from asset.metadata['_ai_suggestions'].
     *
     * GET /assets/{asset}/metadata/suggestions
     *
     * Returns suggestions stored in the new ephemeral format.
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function getSuggestions(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.suggestions.view')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        // Get suggestions from asset.metadata['_ai_suggestions']
        $suggestions = $this->suggestionService->getSuggestions($asset);

        if (empty($suggestions)) {
            return response()->json(['suggestions' => []]);
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

        // Build map of field_key to field definition
        $fieldMap = [];
        foreach ($schema['fields'] ?? [] as $field) {
            $fieldKey = DB::table('metadata_fields')
                ->where('id', $field['field_id'])
                ->value('key');
            if ($fieldKey) {
                $fieldMap[$fieldKey] = $field;
            }
        }

        // Format suggestions with field metadata
        $formattedSuggestions = [];
        foreach ($suggestions as $fieldKey => $suggestion) {
            $fieldDef = $fieldMap[$fieldKey] ?? null;
            if (!$fieldDef) {
                continue; // Skip if field not in schema
            }

            // Check edit permission
            $userRole = $user ? ($user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member') : 'member';
            $canEdit = $this->permissionResolver->canEdit(
                $fieldDef['field_id'],
                $userRole,
                $tenant->id,
                $brand->id,
                $category->id
            );

            // Check apply permission
            $canApply = $user->hasPermissionForTenant($tenant, 'metadata.suggestions.apply');
            $canDismiss = $user->hasPermissionForTenant($tenant, 'metadata.suggestions.dismiss');

            $formattedSuggestions[] = [
                'field_key' => $fieldKey,
                'field_id' => $fieldDef['field_id'],
                'display_label' => $fieldDef['display_label'] ?? $fieldKey,
                'type' => $fieldDef['type'] ?? 'text',
                'options' => $fieldDef['options'] ?? [],
                'value' => $suggestion['value'],
                'confidence' => $suggestion['confidence'] ?? null,
                'source' => $suggestion['source'] ?? 'ai',
                'generated_at' => $suggestion['generated_at'] ?? null,
                'can_edit' => $canEdit,
                'can_apply' => $canApply && $canEdit,
                'can_dismiss' => $canDismiss,
            ];
        }

        return response()->json([
            'suggestions' => $formattedSuggestions,
        ]);
    }

    /**
     * Accept an AI metadata suggestion (write to metadata).
     *
     * POST /assets/{asset}/metadata/suggestions/{fieldKey}/accept
     *
     * @param Asset $asset
     * @param string $fieldKey
     * @return JsonResponse
     */
    public function acceptSuggestion(Asset $asset, string $fieldKey): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.suggestions.apply')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        // Get suggestions
        $suggestions = $this->suggestionService->getSuggestions($asset);
        if (!isset($suggestions[$fieldKey])) {
            return response()->json(['message' => 'Suggestion not found'], 404);
        }

        $suggestion = $suggestions[$fieldKey];

        // Get field ID
        $field = DB::table('metadata_fields')
            ->where('key', $fieldKey)
            ->where('tenant_id', $asset->tenant_id)
            ->first();

        if (!$field) {
            return response()->json(['message' => 'Field not found'], 404);
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
            return response()->json(['message' => 'Category not found'], 404);
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

        // Find field in schema
        $fieldDef = null;
        foreach ($schema['fields'] ?? [] as $f) {
            if ($f['field_id'] === $field->id) {
                $fieldDef = $f;
                break;
            }
        }

        if (!$fieldDef) {
            return response()->json(['message' => 'Field not found in schema'], 404);
        }

        // Check edit permission
        $userRole = $user->getRoleForBrand($brand) ?? $user->getRoleForTenant($tenant) ?? 'member';
        $canEdit = $this->permissionResolver->canEdit(
            $field->id,
            $userRole,
            $tenant->id,
            $brand->id,
            $category->id
        );

        if (!$canEdit) {
            return response()->json(['message' => 'You do not have permission to edit this field'], 403);
        }

        // Check if approval is required
        // Phase M-2: Pass brand for company + brand level gating
        // When applying AI suggestion, it becomes a user edit, so source is 'user'
        $requiresApproval = $this->approvalResolver->requiresApproval('user', $tenant, $user, $brand);

        // Write to asset_metadata
        DB::transaction(function () use ($asset, $field, $suggestion, $user, $requiresApproval) {
            // Get old value for history
            $oldValue = DB::table('asset_metadata')
                ->where('asset_id', $asset->id)
                ->where('metadata_field_id', $field->id)
                ->whereNotNull('approved_at')
                ->value('value_json');
            $oldValueJson = $oldValue ?: null;

            // Delete existing unapproved values for this field
            DB::table('asset_metadata')
                ->where('asset_id', $asset->id)
                ->where('metadata_field_id', $field->id)
                ->whereNull('approved_at')
                ->delete();

            // Insert new value
            // Preserve AI origin: if suggestion came from AI, keep source='ai' and set producer='ai'
            // This allows the UI to show "AI" badge even though user accepted it
            $suggestionSource = $suggestion['source'] ?? 'ai'; // Default to 'ai' for AI suggestions
            $isAISuggestion = ($suggestionSource === 'ai' || isset($suggestion['confidence']));
            
            $assetMetadataId = DB::table('asset_metadata')->insertGetId([
                'asset_id' => $asset->id,
                'metadata_field_id' => $field->id,
                'value_json' => json_encode($suggestion['value']),
                'source' => $isAISuggestion ? 'ai' : 'user', // Preserve AI source if it was an AI suggestion
                'producer' => $isAISuggestion ? 'ai' : 'user', // Mark producer as 'ai' for AI suggestions
                'confidence' => $suggestion['confidence'] ?? null,
                'approved_at' => $requiresApproval ? null : now(),
                'approved_by' => $requiresApproval ? null : $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create audit history entry
            DB::table('asset_metadata_history')->insert([
                'asset_metadata_id' => $assetMetadataId,
                'old_value_json' => $oldValueJson,
                'new_value_json' => json_encode($suggestion['value']),
                'source' => 'user',
                'changed_by' => $user->id,
                'created_at' => now(),
            ]);
        });

        // Remove suggestion from asset.metadata['_ai_suggestions']
        $allSuggestions = $suggestions;
        unset($allSuggestions[$fieldKey]);
        if (empty($allSuggestions)) {
            $this->suggestionService->clearSuggestions($asset);
        } else {
            $this->suggestionService->storeSuggestions($asset, $allSuggestions);
        }

        Log::info('[AssetMetadataController] AI suggestion accepted', [
            'asset_id' => $asset->id,
            'field_key' => $fieldKey,
            'user_id' => $user->id,
        ]);

        // TODO (Optional Enhancement): Soft audit trail
        // If you want to track "who accepted what" for analytics:
        // - Log event: 'ai.suggestion.accepted' with context (user_id, asset_id, field_key, value, confidence)
        // - Could enable metrics like "AI suggestion adoption rate"
        // - No schema change required if using existing events/log system

        return response()->json(['message' => 'Suggestion accepted']);
    }

    /**
     * Dismiss an AI metadata suggestion (remove from suggestions).
     *
     * POST /assets/{asset}/metadata/suggestions/{fieldKey}/dismiss
     *
     * @param Asset $asset
     * @param string $fieldKey
     * @return JsonResponse
     */
    public function dismissSuggestion(Asset $asset, string $fieldKey): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.suggestions.dismiss')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        // Get suggestions
        $suggestions = $this->suggestionService->getSuggestions($asset);
        if (!isset($suggestions[$fieldKey])) {
            return response()->json(['message' => 'Suggestion not found'], 404);
        }

        $suggestion = $suggestions[$fieldKey];
        $value = $suggestion['value'] ?? null;

        // Record dismissal to prevent this suggestion from reappearing
        $this->suggestionService->recordDismissal($asset, $fieldKey, $value);

        // Remove suggestion from active suggestions
        unset($suggestions[$fieldKey]);
        if (empty($suggestions)) {
            $this->suggestionService->clearSuggestions($asset);
        } else {
            $this->suggestionService->storeSuggestions($asset, $suggestions);
        }

        Log::info('[AssetMetadataController] AI suggestion dismissed', [
            'asset_id' => $asset->id,
            'field_key' => $fieldKey,
            'value' => $value,
            'user_id' => $user->id,
        ]);

        // Record dismissal activity event
        try {
            // Get field label for better timeline display
            $fieldLabel = null;
            $field = DB::table('metadata_fields')
                ->where('key', $fieldKey)
                ->where('tenant_id', $tenant->id)
                ->first();
            if ($field) {
                $fieldLabel = $field->label ?? $field->name ?? $fieldKey;
            }

            ActivityRecorder::record(
                tenant: $tenant,
                eventType: \App\Enums\EventType::ASSET_AI_SUGGESTION_DISMISSED,
                subject: $asset,
                actor: $user,
                brand: $brand,
                metadata: [
                    'field_key' => $fieldKey,
                    'field_label' => $fieldLabel,
                    'value' => $value,
                ]
            );
        } catch (\Exception $e) {
            // Activity logging must never break processing
            Log::error('Failed to log AI suggestion dismissal activity', [
                'asset_id' => $asset->id,
                'field_key' => $fieldKey,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['message' => 'Suggestion dismissed']);
    }

    /**
     * Get AI tag suggestions for an asset.
     *
     * GET /assets/{asset}/tags/suggestions
     *
     * Returns tag candidates from asset_tag_candidates table.
     *
     * @param Asset $asset
     * @return JsonResponse
     */
    public function getTagSuggestions(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Check permission (reuse metadata suggestions permission)
        if (!$user->hasPermissionForTenant($tenant, 'metadata.suggestions.view')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        // Get tag candidates from asset_tag_candidates (unresolved, not dismissed)
        $tagCandidates = DB::table('asset_tag_candidates')
            ->where('asset_id', $asset->id)
            ->where('producer', 'ai')
            ->whereNull('resolved_at')
            ->whereNull('dismissed_at')
            ->orderBy('confidence', 'desc')
            ->get();

        // Check permissions for apply/dismiss
        $canApply = $user->hasPermissionForTenant($tenant, 'metadata.suggestions.apply');
        $canDismiss = $user->hasPermissionForTenant($tenant, 'metadata.suggestions.dismiss');

        $suggestions = $tagCandidates->map(function ($candidate) use ($canApply, $canDismiss) {
            return [
                'id' => $candidate->id,
                'tag' => $candidate->tag,
                'confidence' => $candidate->confidence ? (float) $candidate->confidence : null,
                'source' => $candidate->source,
                'can_apply' => $canApply,
                'can_dismiss' => $canDismiss,
            ];
        })->values();

        return response()->json([
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Accept an AI tag suggestion.
     *
     * POST /assets/{asset}/tags/suggestions/{candidateId}/accept
     *
     * Creates tag in asset_tags table and marks candidate as resolved.
     *
     * @param Asset $asset
     * @param int $candidateId
     * @return JsonResponse
     */
    public function acceptTagSuggestion(Asset $asset, int $candidateId): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.suggestions.apply')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        // Check plan tag limit before accepting AI suggestion
        try {
            $this->planService->enforceTagLimit($asset);
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return $e->render($request);
        }

        // Get candidate
        $candidate = DB::table('asset_tag_candidates')
            ->where('id', $candidateId)
            ->where('asset_id', $asset->id)
            ->where('producer', 'ai')
            ->whereNull('resolved_at')
            ->whereNull('dismissed_at')
            ->first();

        if (!$candidate) {
            return response()->json(['message' => 'Tag suggestion not found'], 404);
        }

        // Phase J.2.1: Normalize tag to canonical form
        $tagNormalizationService = app(\App\Services\TagNormalizationService::class);
        $canonicalTag = $tagNormalizationService->normalize($candidate->tag, $tenant);

        // If normalization results in blocked/invalid tag, reject the acceptance
        if ($canonicalTag === null) {
            return response()->json([
                'message' => 'Tag cannot be accepted (blocked or invalid after normalization)',
                'original_tag' => $candidate->tag,
            ], 422);
        }

        // Check if canonical tag already exists (avoid duplicates)
        $existingTag = DB::table('asset_tags')
            ->where('asset_id', $asset->id)
            ->where('tag', $canonicalTag) // Check against canonical form
            ->first();

        DB::transaction(function () use ($asset, $candidate, $candidateId, $user, $existingTag, $canonicalTag) {
            if (!$existingTag) {
                // Create tag in asset_tags table with canonical form
                DB::table('asset_tags')->insert([
                    'asset_id' => $asset->id,
                    'tag' => $canonicalTag, // Store canonical form
                    'source' => 'ai', // Tag was AI-generated, user accepted it
                    'confidence' => $candidate->confidence,
                    'created_at' => now(),
                ]);
            }

            // Mark candidate as resolved
            DB::table('asset_tag_candidates')
                ->where('id', $candidateId)
                ->update([
                    'resolved_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        Log::info('[AssetMetadataController] AI tag suggestion accepted', [
            'asset_id' => $asset->id,
            'candidate_id' => $candidateId,
            'original_tag' => $candidate->tag,
            'canonical_tag' => $canonicalTag,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Tag accepted',
            'canonical_tag' => $canonicalTag,
        ]);
    }

    /**
     * Dismiss an AI tag suggestion.
     *
     * POST /assets/{asset}/tags/suggestions/{candidateId}/dismiss
     *
     * Marks candidate as dismissed to prevent it from reappearing.
     *
     * @param Asset $asset
     * @param int $candidateId
     * @return JsonResponse
     */
    public function dismissTagSuggestion(Asset $asset, int $candidateId): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        // Verify asset belongs to tenant and brand
        if ($asset->tenant_id !== $tenant->id || $asset->brand_id !== $brand->id) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.suggestions.dismiss')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        // Get candidate
        $candidate = DB::table('asset_tag_candidates')
            ->where('id', $candidateId)
            ->where('asset_id', $asset->id)
            ->where('producer', 'ai')
            ->whereNull('resolved_at')
            ->whereNull('dismissed_at')
            ->first();

        if (!$candidate) {
            return response()->json(['message' => 'Tag suggestion not found'], 404);
        }

        // Phase J.2.1: Normalize tag to canonical form for dismissal
        $tagNormalizationService = app(\App\Services\TagNormalizationService::class);
        $canonicalTag = $tagNormalizationService->normalize($candidate->tag, $tenant);

        DB::transaction(function () use ($asset, $candidateId, $canonicalTag, $candidate, $tagNormalizationService, $tenant) {
            // Mark the specific candidate as dismissed
            DB::table('asset_tag_candidates')
                ->where('id', $candidateId)
                ->update([
                    'dismissed_at' => now(),
                    'updated_at' => now(),
                ]);

            // Phase J.2.1: Also dismiss any other unresolved candidates that normalize to the same canonical form
            if ($canonicalTag !== null) {
                $allCandidates = DB::table('asset_tag_candidates')
                    ->where('asset_id', $asset->id)
                    ->where('producer', 'ai')
                    ->whereNull('resolved_at')
                    ->whereNull('dismissed_at')
                    ->get();

                $candidatesToDismiss = [];
                foreach ($allCandidates as $otherCandidate) {
                    $otherCanonical = $tagNormalizationService->normalize($otherCandidate->tag, $tenant);
                    if ($otherCanonical === $canonicalTag) {
                        $candidatesToDismiss[] = $otherCandidate->id;
                    }
                }

                if (!empty($candidatesToDismiss)) {
                    DB::table('asset_tag_candidates')
                        ->whereIn('id', $candidatesToDismiss)
                        ->update([
                            'dismissed_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
            }
        });

        Log::info('[AssetMetadataController] AI tag suggestion dismissed', [
            'asset_id' => $asset->id,
            'candidate_id' => $candidateId,
            'original_tag' => $candidate->tag,
            'canonical_tag' => $canonicalTag,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Tag dismissed',
            'canonical_tag' => $canonicalTag,
        ]);
    }

    /**
     * Get all pending AI suggestions across all assets (for dashboard tile).
     *
     * GET /api/pending-ai-suggestions
     *
     * Phase M-1: Returns a consolidated list of:
     * - Tag candidates (from asset_tag_candidates)
     * - Metadata candidates (from asset_metadata_candidates, AI only)
     * 
     * Note: Pending metadata from asset_metadata table is excluded.
     * Metadata approval is asset-centric and reviewed inline during asset review.
     *
     * @return JsonResponse
     */
    public function getAllPendingSuggestions(): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        if (!$tenant || !$brand) {
            return response()->json(['message' => 'Tenant and brand must be selected'], 403);
        }

        // Check permission
        if (!$user->hasPermissionForTenant($tenant, 'metadata.suggestions.view')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $items = [];
        $assetIds = [];

        // 1. Get tag candidates
        $tagCandidates = DB::table('asset_tag_candidates')
            ->join('assets', 'asset_tag_candidates.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->where('asset_tag_candidates.producer', 'ai')
            ->whereNull('asset_tag_candidates.resolved_at')
            ->whereNull('asset_tag_candidates.dismissed_at')
            ->select(
                'asset_tag_candidates.id',
                'asset_tag_candidates.asset_id',
                'asset_tag_candidates.tag as value',
                'asset_tag_candidates.confidence',
                'asset_tag_candidates.source',
                'assets.title as asset_title',
                'assets.original_filename as asset_filename',
                'assets.thumbnail_status',
                'assets.metadata'
            )
            ->get();

        foreach ($tagCandidates as $candidate) {
            $assetIds[] = $candidate->asset_id;
        }

        // 2. Get metadata candidates (AI only)
        // Phase M-1: Only AI candidates are shown as suggestions
        $metadataCandidates = DB::table('asset_metadata_candidates')
            ->join('assets', 'asset_metadata_candidates.asset_id', '=', 'assets.id')
            ->join('metadata_fields', 'asset_metadata_candidates.metadata_field_id', '=', 'metadata_fields.id')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->whereNull('asset_metadata_candidates.resolved_at')
            ->whereNull('asset_metadata_candidates.dismissed_at')
            ->where('asset_metadata_candidates.producer', 'ai') // Phase M-1: Only AI candidates
            ->select(
                'asset_metadata_candidates.id',
                'asset_metadata_candidates.asset_id',
                'asset_metadata_candidates.metadata_field_id',
                'asset_metadata_candidates.value_json',
                'asset_metadata_candidates.confidence',
                'asset_metadata_candidates.source',
                'asset_metadata_candidates.producer',
                'metadata_fields.key as field_key',
                'metadata_fields.system_label as field_label',
                'metadata_fields.type as field_type',
                'assets.title as asset_title',
                'assets.original_filename as asset_filename',
                'assets.thumbnail_status',
                'assets.metadata'
            )
            ->get();

        foreach ($metadataCandidates as $candidate) {
            $assetIds[] = $candidate->asset_id;
        }

        // Get options for select fields
        $fieldIds = $metadataCandidates->pluck('metadata_field_id')->unique();
        $optionsMap = [];
        if ($fieldIds->isNotEmpty()) {
            $options = DB::table('metadata_options')
                ->whereIn('metadata_field_id', $fieldIds)
                ->select('metadata_field_id', 'value', 'system_label as display_label')
                ->get()
                ->groupBy('metadata_field_id');

            foreach ($options as $fieldId => $opts) {
                $optionsMap[$fieldId] = $opts->map(fn($opt) => [
                    'value' => $opt->value,
                    'display_label' => $opt->display_label,
                ])->toArray();
            }
        }

        // Phase M-1: Exclude asset_metadata.approved_at IS NULL from suggestions
        // Metadata approval is asset-centric and inline - no separate queue
        // Pending metadata is reviewed during asset review, not as separate suggestions

        // Load all assets in bulk to avoid N+1 queries
        $assetIds = array_unique($assetIds);
        $assets = Asset::whereIn('id', $assetIds)
            ->get()
            ->keyBy('id');

        // Process tag candidates
        foreach ($tagCandidates as $candidate) {
            $asset = $assets->get($candidate->asset_id);
            $thumbnailUrls = $this->getThumbnailUrls($asset);
            
            // Get thumbnail status and metadata for ThumbnailPreview component
            $thumbnailStatus = $asset ? ($asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus 
                ? $asset->thumbnail_status->value 
                : ($asset->thumbnail_status ?? 'pending')) : 'pending';
            $metadata = $asset ? ($asset->metadata ?? []) : [];
            
            $items[] = [
                'id' => $candidate->id,
                'asset_id' => $candidate->asset_id,
                'type' => 'tag',
                'tag' => $candidate->value,
                'value' => $candidate->value,
                'confidence' => $candidate->confidence ? (float) $candidate->confidence : null,
                'source' => $candidate->source,
                'asset_title' => $candidate->asset_title,
                'asset_filename' => $candidate->asset_filename,
                'final_thumbnail_url' => $thumbnailUrls['final'] ?? null,
                'preview_thumbnail_url' => $thumbnailUrls['preview'] ?? null,
                'thumbnail_status' => $thumbnailStatus,
                'metadata' => $metadata,
                'mime_type' => $asset->mime_type ?? null,
                'file_extension' => $asset->file_extension ?? null,
            ];
        }

        // Process metadata candidates
        foreach ($metadataCandidates as $candidate) {
            $asset = $assets->get($candidate->asset_id);
            $thumbnailUrls = $this->getThumbnailUrls($asset);
            $value = json_decode($candidate->value_json, true);
            
            // Get thumbnail status and metadata for ThumbnailPreview component
            $thumbnailStatus = $asset ? ($asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus 
                ? $asset->thumbnail_status->value 
                : ($asset->thumbnail_status ?? 'pending')) : 'pending';
            $metadata = $asset ? ($asset->metadata ?? []) : [];
            
            $items[] = [
                'id' => $candidate->id,
                'asset_id' => $candidate->asset_id,
                'type' => 'metadata_candidate',
                'value' => $value,
                'field_key' => $candidate->field_key,
                'field_label' => $candidate->field_label,
                'field_type' => $candidate->field_type,
                'confidence' => $candidate->confidence ? (float) $candidate->confidence : null,
                'source' => $candidate->source,
                'producer' => $candidate->producer,
                'options' => $optionsMap[$candidate->metadata_field_id] ?? [],
                'asset_title' => $candidate->asset_title,
                'asset_filename' => $candidate->asset_filename,
                'final_thumbnail_url' => $thumbnailUrls['final'] ?? null,
                'preview_thumbnail_url' => $thumbnailUrls['preview'] ?? null,
                'thumbnail_status' => $thumbnailStatus,
                'metadata' => $metadata,
                'mime_type' => $asset->mime_type ?? null,
                'file_extension' => $asset->file_extension ?? null,
            ];
        }

        // Phase M-1: Pending metadata from asset_metadata table is excluded
        // Metadata is reviewed inline during asset review, not as separate suggestions

        // Sort by confidence descending (highest first), then by created_at
        usort($items, function ($a, $b) {
            $confA = $a['confidence'] ?? 0;
            $confB = $b['confidence'] ?? 0;
            if ($confB !== $confA) {
                return $confB <=> $confA;
            }
            return 0;
        });

        return response()->json([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    /**
     * Get thumbnail URLs for an asset (matches AssetController structure).
     * Returns both preview and final thumbnail URLs separately.
     *
     * @param Asset|null $asset
     * @return array{preview: string|null, final: string|null}
     */
    protected function getThumbnailUrls(?Asset $asset): array
    {
        if (!$asset) {
            return ['preview' => null, 'final' => null];
        }

        $metadata = $asset->metadata ?? [];
        $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus 
            ? $asset->thumbnail_status->value 
            : ($asset->thumbnail_status ?? 'pending');

        // Preview thumbnail URL (temporary, available early)
        $previewThumbnailUrl = null;
        $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
        if (!empty($previewThumbnails) && isset($previewThumbnails['preview'])) {
            $previewThumbnailUrl = route('assets.thumbnail.preview', [
                'asset' => $asset->id,
                'style' => 'preview',
            ]);
        }

        // Final thumbnail URL (permanent, only when completed)
        $finalThumbnailUrl = null;
        $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
        $thumbnails = $metadata['thumbnails'] ?? [];
        $thumbnailsExistInMetadata = !empty($thumbnails) && (isset($thumbnails['thumb']) || isset($thumbnails['medium']));

        if ($thumbnailStatus === 'completed' || $thumbnailsExistInMetadata) {
            // Prefer medium size for better quality, fallback to thumb if medium not available
            $thumbnailStyle = 'medium';
            $thumbnailPath = $asset->thumbnailPathForStyle('medium');
            
            // Fallback to 'thumb' if medium doesn't exist
            if (!$thumbnailPath && !isset($thumbnails['medium'])) {
                $thumbnailStyle = 'thumb';
                $thumbnailPath = $asset->thumbnailPathForStyle('thumb');
            }
            
            if ($thumbnailPath || $thumbnailsExistInMetadata) {
                $finalThumbnailUrl = route('assets.thumbnail.final', [
                    'asset' => $asset->id,
                    'style' => $thumbnailStyle,
                ]);
                
                // Add version query param if available (ensures browser refetches when version changes)
                if ($thumbnailVersion) {
                    $finalThumbnailUrl .= '?v=' . urlencode($thumbnailVersion);
                }
            }
        }

        return [
            'preview' => $previewThumbnailUrl,
            'final' => $finalThumbnailUrl,
        ];
    }
}
