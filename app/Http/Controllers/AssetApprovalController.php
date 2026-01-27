<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Enums\AssetType;
use App\Enums\EventType;
use App\Models\Asset;
use App\Models\Brand;
use App\Services\ActivityRecorder;
use App\Services\ApprovalAgingService;
use App\Services\ApprovalSummaryService;
use App\Services\AssetApprovalCommentService;
use App\Services\FeatureGate;
use App\Support\Roles\PermissionMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Phase AF-1: Asset Approval Controller
 * 
 * Handles approval and rejection of assets uploaded by users with requires_approval = true.
 * Approval authority is derived from PermissionMap (approval_capable roles).
 */
class AssetApprovalController extends Controller
{
    /**
     * Get pending assets for review modal (Phase J.2).
     * 
     * GET /api/brands/{brand}/pending-assets
     * 
     * Returns assets with approval_status = pending or rejected for review.
     * Approvers see all, contributors see only their own.
     */
    public function pendingAssets(Request $request, Brand $brand): JsonResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Brand does not belong to this tenant.'], 403);
        }

        // Phase AF-5: Gate approval queue access based on plan feature
        $featureGate = app(FeatureGate::class);
        if (!$featureGate->approvalsEnabled($tenant)) {
            return response()->json([
                'error' => 'Approval workflows are not available on your current plan.',
            ], 403);
        }

        // Phase MI-1: Verify active brand membership first
        $membership = $user->activeBrandMembership($brand);
        if (!$membership) {
            return response()->json([
                'error' => 'You do not have active membership for this brand.',
            ], 403);
        }
        
        // Check permissions: Only Owner/Admin/Brand Manager can access
        $brandRole = $membership['role'];
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin']);
        $isBrandManager = $brandRole === 'brand_manager';
        $isContributor = $brandRole === 'contributor';
        
        // Contributors should never see this endpoint
        if ($isContributor && !$isTenantOwnerOrAdmin && !$isBrandManager) {
            return response()->json([
                'error' => 'You do not have permission to view pending assets.',
            ], 403);
        }

        // Query pending/rejected assets
        $query = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::ASSET)
            ->where(function ($q) {
                $q->where('approval_status', ApprovalStatus::PENDING)
                  ->orWhere('approval_status', ApprovalStatus::REJECTED);
            })
            ->whereNull('deleted_at')
            ->with(['user'])
            ->orderBy('created_at', 'desc');
        
        // Filter by category if provided
        if ($request->has('category_id') && $request->category_id) {
            $categoryId = (int) $request->category_id;
            // Filter assets where metadata->category_id matches the category ID
            // Use direct JSON path comparison for exact integer match
            // Cast categoryId to integer to ensure type matching with JSON integer values
            // Note: category_id is stored in metadata JSON, not as a direct column
            $query->whereNotNull('metadata')
                ->where('metadata->category_id', $categoryId);
        }
        
        // Approvers see all, contributors see only their own (though they shouldn't reach here)
        if ($isContributor && !$isTenantOwnerOrAdmin && !$isBrandManager) {
            $query->where('user_id', $user->id);
        }
        
        $assets = $query->get()->map(function ($asset) {
            // Get thumbnail URLs
            $metadata = $asset->metadata ?? [];
            $thumbnailStatus = $asset->thumbnail_status instanceof \App\Enums\ThumbnailStatus 
                ? $asset->thumbnail_status->value 
                : ($asset->thumbnail_status ?? 'pending');
            
            $previewThumbnailUrl = null;
            $previewThumbnails = $metadata['preview_thumbnails'] ?? [];
            if (!empty($previewThumbnails) && isset($previewThumbnails['preview'])) {
                $previewThumbnailUrl = route('assets.thumbnail.preview', [
                    'asset' => $asset->id,
                    'style' => 'preview',
                ]);
            }
            
            $finalThumbnailUrl = null;
            if ($thumbnailStatus === 'completed') {
                $thumbnailVersion = $metadata['thumbnails_generated_at'] ?? null;
                $thumbnails = $metadata['thumbnails'] ?? [];
                if (!empty($thumbnails) && isset($thumbnails['medium'])) {
                    $finalThumbnailUrl = route('assets.thumbnail.final', [
                        'asset' => $asset->id,
                        'style' => 'medium',
                    ]);
                    if ($thumbnailVersion) {
                        $finalThumbnailUrl .= '?v=' . $thumbnailVersion;
                    }
                }
            }
            
            // Get category_id from asset (could be in category_id column or metadata)
            $categoryId = $asset->category_id ?? ($asset->metadata['category_id'] ?? null);
            
            return [
                'id' => $asset->id,
                'title' => $asset->title ?? $asset->original_filename ?? 'Untitled',
                'original_filename' => $asset->original_filename,
                'mime_type' => $asset->mime_type,
                'size_bytes' => $asset->size_bytes,
                'created_at' => $asset->created_at?->toISOString(),
                'category_id' => $categoryId,
                'category' => $asset->category ? [
                    'id' => $asset->category->id,
                    'name' => $asset->category->name,
                ] : null,
                'uploader' => $asset->user ? [
                    'id' => $asset->user->id,
                    'name' => trim($asset->user->name) ?: null, // Convert empty string to null for proper fallback
                    'first_name' => $asset->user->first_name,
                    'last_name' => $asset->user->last_name,
                    'email' => $asset->user->email,
                    'avatar_url' => $asset->user->avatar_url,
                ] : null,
                'approval_status' => $asset->approval_status->value,
                'rejected_at' => $asset->rejected_at?->toISOString(),
                'rejection_reason' => $asset->rejection_reason,
                'metadata' => $asset->metadata,
                'final_thumbnail_url' => $finalThumbnailUrl,
                'preview_thumbnail_url' => $previewThumbnailUrl,
                'thumbnail_status' => $thumbnailStatus,
            ];
        });

        return response()->json([
            'assets' => $assets,
            'count' => $assets->count(),
        ]);
    }

    /**
     * Get approval queue (pending assets).
     * 
     * GET /brands/{brand}/approvals
     */
    public function index(Request $request, Brand $brand): JsonResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Brand does not belong to this tenant.'], 403);
        }

        // Phase AF-5: Gate approval queue access based on plan feature
        $featureGate = app(FeatureGate::class);
        if (!$featureGate->approvalsEnabled($tenant)) {
            $requiredPlan = $featureGate->getRequiredPlanName($tenant);
            return response()->json([
                'error' => 'Approval workflows are not available on your current plan.',
                'required_plan' => $requiredPlan,
                'message' => "Approval workflows require {$requiredPlan} plan or higher.",
            ], 403);
        }

        // Phase MI-1: Verify active brand membership first
        $membership = $user->activeBrandMembership($brand);
        if (!$membership) {
            return response()->json([
                'error' => 'You do not have active membership for this brand.',
            ], 403);
        }
        
        // Check if user is approval_capable for this brand
        $brandRole = $membership['role'];
        if (!$brandRole || !PermissionMap::canApproveAssets($brandRole)) {
            return response()->json([
                'error' => 'You do not have permission to view the approval queue.',
                'required_role' => 'admin or brand_manager',
            ], 403);
        }

        // Phase AF-4: Get pending assets with aging metrics
        $agingService = app(ApprovalAgingService::class);
        
        $assets = Asset::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('type', AssetType::ASSET)
            ->where('approval_status', ApprovalStatus::PENDING)
            ->whereNull('deleted_at')
            ->with(['user'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($asset) use ($agingService) {
                $agingMetrics = $agingService->getAgingMetrics($asset);
                
                return [
                    'id' => $asset->id,
                    'title' => $asset->title,
                    'original_filename' => $asset->original_filename,
                    'mime_type' => $asset->mime_type,
                    'size_bytes' => $asset->size_bytes,
                    'created_at' => $asset->created_at?->toISOString(),
                    'uploader' => $asset->user ? [
                        'id' => $asset->user->id,
                        'name' => trim($asset->user->name) ?: null, // Convert empty string to null for proper fallback
                        'first_name' => $asset->user->first_name,
                        'last_name' => $asset->user->last_name,
                        'email' => $asset->user->email,
                        'avatar_url' => $asset->user->avatar_url,
                    ] : null,
                    'approval_status' => $asset->approval_status->value,
                    'metadata' => $asset->metadata,
                    // Phase AF-4: Aging metrics
                    'pending_since' => $agingMetrics['pending_since'],
                    'pending_days' => $agingMetrics['pending_days'],
                    'last_action_at' => $agingMetrics['last_action_at'],
                    'aging_label' => $agingMetrics['aging_label'],
                    'is_aged' => $agingService->shouldHighlight($asset, 7), // Highlight if pending 7+ days
                ];
            });

        return response()->json([
            'assets' => $assets,
            'count' => $assets->count(),
        ]);
    }
    /**
     * Approve an asset.
     * 
     * POST /brands/{brand}/assets/{asset}/approve
     */
    public function approve(Request $request, Brand $brand, Asset $asset): JsonResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Brand does not belong to this tenant.'], 403);
        }

        // Verify asset belongs to brand
        if ($asset->brand_id !== $brand->id) {
            return response()->json(['error' => 'Asset does not belong to this brand.'], 403);
        }

        // Phase MI-1: Verify active brand membership first
        $membership = $user->activeBrandMembership($brand);
        if (!$membership) {
            return response()->json([
                'error' => 'You do not have active membership for this brand.',
            ], 403);
        }
        
        // Check if user is approval_capable for this brand
        $brandRole = $membership['role'];
        if (!$brandRole || !PermissionMap::canApproveAssets($brandRole)) {
            return response()->json([
                'error' => 'You do not have permission to approve assets.',
                'required_role' => 'admin or brand_manager',
            ], 403);
        }

        // Phase J.2: Allow approving pending or rejected assets
        if ($asset->approval_status !== ApprovalStatus::PENDING && $asset->approval_status !== ApprovalStatus::REJECTED) {
            return response()->json([
                'error' => 'Asset is not pending or rejected approval.',
                'current_status' => $asset->approval_status->value,
            ], 422);
        }

        // Update title if provided in request
        if ($request->has('title') && $request->input('title') !== null) {
            $asset->title = $request->input('title');
        }

        // Approve the asset
        $asset->approval_status = ApprovalStatus::APPROVED;
        $asset->approved_at = now();
        $asset->approved_by_user_id = $user->id;
        // Phase J.2: Clear rejection fields when approving
        $asset->rejected_at = null;
        $asset->rejection_reason = null;
        // Auto-publish when approved
        if (!$asset->published_at) {
            $asset->published_at = now();
            $asset->published_by_id = $user->id;
        }
        $asset->save();

        // Phase AF-2: Record approval comment
        $commentService = app(AssetApprovalCommentService::class);
        $commentService->recordApproved($asset, $user, $request->input('comment'));

        // Phase AF-3: Notify uploader
        $notificationService = app(\App\Services\ApprovalNotificationService::class);
        $notificationService->notifyOnApproved($asset, $user);

        // Dispatch AI jobs after approval (tags and metadata suggestions)
        // These jobs run after approval so assets going through approval workflow get AI processing
        try {
            // Refresh asset to get latest state (including thumbnail_status)
            $asset->refresh();
            
            // Check if thumbnails are ready (AI jobs require thumbnails)
            $thumbnailReady = $asset->thumbnail_status === \App\Enums\ThumbnailStatus::COMPLETED;
            
            if ($thumbnailReady) {
                $policyService = app(\App\Services\AiTagPolicyService::class);
                $policyCheck = $policyService->shouldProceedWithAiTagging($asset);
                
                if ($policyCheck['should_proceed']) {
                    // Check if AI jobs have already run (idempotency)
                    $metadata = $asset->metadata ?? [];
                    $aiTaggingCompleted = $metadata['ai_tagging_completed'] ?? false;
                    $aiMetadataCompleted = $metadata['ai_metadata_generation_completed'] ?? false;
                    
                    // Only dispatch if not already completed
                    if (!$aiTaggingCompleted) {
                        \App\Jobs\AITaggingJob::dispatch($asset->id);
                    }
                    
                    if (!$aiMetadataCompleted) {
                        \App\Jobs\AiMetadataGenerationJob::dispatch($asset->id);
                        \App\Jobs\AiTagAutoApplyJob::dispatch($asset->id);
                        \App\Jobs\AiMetadataSuggestionJob::dispatch($asset->id);
                    }
                    
                    Log::info('[AssetApprovalController] AI jobs dispatched after approval', [
                        'asset_id' => $asset->id,
                        'ai_tagging_dispatched' => !$aiTaggingCompleted,
                        'ai_metadata_dispatched' => !$aiMetadataCompleted,
                    ]);
                } else {
                    Log::info('[AssetApprovalController] AI jobs skipped after approval due to policy', [
                        'asset_id' => $asset->id,
                        'reason' => $policyCheck['reason'] ?? 'policy_denied',
                    ]);
                }
            } else {
                Log::info('[AssetApprovalController] AI jobs skipped after approval - thumbnails not ready', [
                    'asset_id' => $asset->id,
                    'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
                ]);
            }
        } catch (\Exception $e) {
            // Don't fail approval if AI job dispatch fails
            Log::error('[AssetApprovalController] Failed to dispatch AI jobs after approval', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Log activity
        try {
            ActivityRecorder::record(
                tenant: $tenant,
                eventType: EventType::ASSET_APPROVED,
                subject: $asset,
                actor: $user,
                brand: $brand,
                metadata: [
                    'approved_by' => $user->name,
                    'approved_by_email' => $user->email,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to log asset approval activity', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('[AssetApprovalController] Asset approved', [
            'asset_id' => $asset->id,
            'approved_by_user_id' => $user->id,
            'brand_id' => $brand->id,
        ]);

        return response()->json([
            'message' => 'Asset approved successfully.',
            'asset' => [
                'id' => $asset->id,
                'approval_status' => $asset->approval_status->value,
                'approved_at' => $asset->approved_at?->toISOString(),
                'approved_by' => [
                    'id' => $user->id,
                    'name' => $user->name,
                ],
            ],
        ]);
    }

    /**
     * Reject an asset.
     * 
     * POST /brands/{brand}/assets/{asset}/reject
     */
    public function reject(Request $request, Brand $brand, Asset $asset): JsonResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Brand does not belong to this tenant.'], 403);
        }

        // Verify asset belongs to brand
        if ($asset->brand_id !== $brand->id) {
            return response()->json(['error' => 'Asset does not belong to this brand.'], 403);
        }

        // Phase MI-1: Verify active brand membership first
        $membership = $user->activeBrandMembership($brand);
        if (!$membership) {
            return response()->json([
                'error' => 'You do not have active membership for this brand.',
            ], 403);
        }
        
        // Check if user is approval_capable for this brand
        $brandRole = $membership['role'];
        if (!$brandRole || !PermissionMap::canApproveAssets($brandRole)) {
            return response()->json([
                'error' => 'You do not have permission to reject assets.',
                'required_role' => 'admin or brand_manager',
            ], 403);
        }

        // Verify asset is in pending state
        if ($asset->approval_status !== ApprovalStatus::PENDING) {
            return response()->json([
                'error' => 'Asset is not pending approval.',
                'current_status' => $asset->approval_status->value,
            ], 422);
        }

        // Phase J.2: Validate rejection reason (min 10 chars)
        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string|min:10|max:1000',
            'title' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Update title if provided in request
        if ($request->has('title') && $request->input('title') !== null) {
            $asset->title = $request->input('title');
        }

        // Reject the asset
        $rejectionReason = $request->input('rejection_reason');
        $asset->approval_status = ApprovalStatus::REJECTED;
        $asset->rejected_at = now();
        $asset->rejection_reason = $rejectionReason; // Summary on asset
        // Keep unpublished when rejected
        $asset->published_at = null;
        $asset->published_by_id = null;
        $asset->save();

        // Phase AF-2: Record rejection comment (detailed comment in history)
        $commentService = app(AssetApprovalCommentService::class);
        $commentService->recordRejected($asset, $user, $rejectionReason);

        // Phase AF-3: Notify uploader
        $notificationService = app(\App\Services\ApprovalNotificationService::class);
        $notificationService->notifyOnRejected($asset, $user, $rejectionReason);

        // Phase AF-6: Generate approval summary (non-blocking)
        try {
            $summaryService = app(ApprovalSummaryService::class);
            $summaryService->generateSummary($asset);
        } catch (\Exception $e) {
            // Failures must not block workflow
            Log::warning('[AssetApprovalController] Failed to generate approval summary', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Log activity
        try {
            ActivityRecorder::record(
                tenant: $tenant,
                eventType: EventType::ASSET_REJECTED,
                subject: $asset,
                actor: $user,
                brand: $brand,
                metadata: [
                    'rejected_by' => $user->name,
                    'rejected_by_email' => $user->email,
                    'rejection_reason' => $request->input('rejection_reason'),
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to log asset rejection activity', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('[AssetApprovalController] Asset rejected', [
            'asset_id' => $asset->id,
            'rejected_by_user_id' => $user->id,
            'brand_id' => $brand->id,
            'rejection_reason' => $request->input('rejection_reason'),
        ]);

        return response()->json([
            'message' => 'Asset rejected successfully.',
            'asset' => [
                'id' => $asset->id,
                'approval_status' => $asset->approval_status->value,
                'rejected_at' => $asset->rejected_at?->toISOString(),
                'rejection_reason' => $asset->rejection_reason,
            ],
        ]);
    }

    /**
     * Resubmit a rejected asset.
     * 
     * POST /brands/{brand}/assets/{asset}/resubmit
     */
    public function resubmit(Request $request, Brand $brand, Asset $asset): JsonResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Brand does not belong to this tenant.'], 403);
        }

        // Verify asset belongs to brand
        if ($asset->brand_id !== $brand->id) {
            return response()->json(['error' => 'Asset does not belong to this brand.'], 403);
        }

        // Verify asset is in rejected state
        if ($asset->approval_status !== ApprovalStatus::REJECTED) {
            return response()->json([
                'error' => 'Asset is not rejected. Only rejected assets can be resubmitted.',
                'current_status' => $asset->approval_status->value,
            ], 422);
        }

        // Phase MI-1: Verify active brand membership first
        $membership = $user->activeBrandMembership($brand);
        if (!$membership) {
            return response()->json([
                'error' => 'You do not have active membership for this brand.',
            ], 403);
        }
        
        // Check permissions: Only uploader or brand admin may resubmit
        $isUploader = $asset->user_id === $user->id;
        $brandRole = $membership['role'];
        $isBrandAdmin = $brandRole === 'admin';
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantAdmin = in_array($tenantRole, ['owner', 'admin']);

        if (!$isUploader && !$isBrandAdmin && !$isTenantAdmin) {
            return response()->json([
                'error' => 'You do not have permission to resubmit this asset.',
                'required' => 'Must be the uploader or a brand/tenant admin',
            ], 403);
        }

        // Resubmit the asset
        $asset->approval_status = ApprovalStatus::PENDING;
        $asset->rejected_at = null;
        $asset->rejection_reason = null;
        // Keep unpublished until approved
        $asset->published_at = null;
        $asset->published_by_id = null;
        $asset->save();

        // Phase AF-2: Record resubmission comment
        $commentService = app(AssetApprovalCommentService::class);
        $commentService->recordResubmitted($asset, $user, $request->input('comment'));

        // Phase AF-3: Notify approvers
        $notificationService = app(\App\Services\ApprovalNotificationService::class);
        $notificationService->notifyOnResubmitted($asset, $user);

        // Phase AF-6: Generate approval summary (non-blocking)
        try {
            $summaryService = app(ApprovalSummaryService::class);
            $summaryService->generateSummary($asset);
        } catch (\Exception $e) {
            // Failures must not block workflow
            Log::warning('[AssetApprovalController] Failed to generate approval summary', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Log activity
        try {
            ActivityRecorder::record(
                tenant: $tenant,
                eventType: EventType::ASSET_UPDATED,
                subject: $asset,
                actor: $user,
                brand: $brand,
                metadata: [
                    'action' => 'resubmitted',
                    'resubmitted_by' => $user->name,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to log asset resubmission activity', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('[AssetApprovalController] Asset resubmitted', [
            'asset_id' => $asset->id,
            'resubmitted_by_user_id' => $user->id,
            'brand_id' => $brand->id,
        ]);

        return response()->json([
            'message' => 'Asset resubmitted successfully.',
            'asset' => [
                'id' => $asset->id,
                'approval_status' => $asset->approval_status->value,
            ],
        ]);
    }

    /**
     * Get approval history for an asset.
     * 
     * GET /brands/{brand}/assets/{asset}/approval-history
     */
    public function history(Request $request, Brand $brand, Asset $asset): JsonResponse
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Brand does not belong to this tenant.'], 403);
        }

        // Verify asset belongs to brand
        if ($asset->brand_id !== $brand->id) {
            return response()->json(['error' => 'Asset does not belong to this brand.'], 403);
        }

        // Phase MI-1: Verify active brand membership first
        $membership = $user->activeBrandMembership($brand);
        if (!$membership) {
            // Check if user is tenant admin (they can view even without brand membership)
            $tenantRole = $user->getRoleForTenant($tenant);
            $isTenantAdmin = in_array($tenantRole, ['owner', 'admin']);
            if (!$isTenantAdmin) {
                return response()->json([
                    'error' => 'You do not have active membership for this brand.',
                ], 403);
            }
        }
        
        // Check permissions: Contributors may view their own assets, approvers may view any
        $isUploader = $asset->user_id === $user->id;
        $brandRole = $membership['role'] ?? null;
        $isApprover = $brandRole && PermissionMap::canApproveAssets($brandRole);
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantAdmin = in_array($tenantRole, ['owner', 'admin']);

        if (!$isUploader && !$isApprover && !$isTenantAdmin) {
            return response()->json([
                'error' => 'You do not have permission to view approval history.',
            ], 403);
        }

        // Get approval history
        $comments = \App\Models\AssetApprovalComment::where('asset_id', $asset->id)
            ->with('user')
            ->orderBy('created_at', 'desc') // Phase J.3: Sort newest → oldest
            ->get()
            ->map(function ($comment) use ($brand, $tenant) {
                // Phase J.3: Determine user role for badge display
                $userRole = null;
                $isApprover = false;
                if ($comment->user) {
                    $membership = $comment->user->activeBrandMembership($brand);
                    $brandRole = $membership['role'] ?? null;
                    $tenantRole = $comment->user->getRoleForTenant($tenant);
                    
                    if (in_array($tenantRole, ['owner', 'admin'])) {
                        $userRole = $tenantRole === 'owner' ? 'Owner' : 'Admin';
                        $isApprover = true;
                    } elseif ($brandRole && PermissionMap::canApproveAssets($brandRole)) {
                        $userRole = $brandRole === 'brand_manager' ? 'Brand Manager' : 'Admin';
                        $isApprover = true;
                    }
                }
                
                return [
                    'id' => $comment->id,
                    'action' => $comment->action->value,
                    'action_label' => ucfirst(str_replace('_', ' ', $comment->action->value)),
                    'comment' => $comment->comment,
                    'user' => $comment->user ? [
                        'id' => $comment->user->id,
                        'name' => $comment->user->name,
                        'email' => $comment->user->email,
                    ] : null,
                    'user_role' => $userRole, // Phase J.3: Role for badge display
                    'is_approver' => $isApprover, // Phase J.3: Whether user is an approver
                    'created_at' => $comment->created_at?->toISOString(),
                ];
            });

        return response()->json([
            'comments' => $comments,
            'count' => $comments->count(),
            // Phase AF-6: Include approval summary in history response
            'approval_summary' => $asset->approval_summary,
            'approval_summary_generated_at' => $asset->approval_summary_generated_at?->toISOString(),
        ]);
    }
}
