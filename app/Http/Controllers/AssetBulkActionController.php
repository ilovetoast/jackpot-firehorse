<?php

namespace App\Http\Controllers;

use App\Enums\AssetBulkAction;
use App\Services\Assets\BulkActionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Phase B1: Bulk actions for assets (lifecycle, approval, trash, metadata).
 *
 * One action per request. POST /assets/bulk-action
 */
class AssetBulkActionController extends Controller
{
    public function __construct(
        protected BulkActionService $bulkActionService
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        if (! $tenant) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'required|uuid|exists:assets,id',
            'action' => ['required', 'string', Rule::in(array_map(fn ($c) => $c->value, AssetBulkAction::cases()))],
            'payload' => 'nullable|array',
        ]);

        $sitePipelineActions = [
            AssetBulkAction::SITE_RERUN_THUMBNAILS->value,
            AssetBulkAction::SITE_RERUN_AI_METADATA_TAGGING->value,
        ];
        if (in_array($validated['action'], $sitePipelineActions, true) && count($validated['asset_ids']) > 100) {
            return response()->json([
                'message' => 'Select at most 100 assets per site pipeline bulk action.',
                'errors' => ['asset_ids' => ['Maximum 100 assets per request.']],
            ], 422);
        }

        $action = $validated['action'];
        $payload = $validated['payload'] ?? [];

        if (AssetBulkAction::REJECT->value === $action) {
            $reason = $payload['rejection_reason'] ?? null;
            if ($reason === null || trim((string) $reason) === '') {
                return response()->json([
                    'message' => 'Rejection reason is required for REJECT action.',
                    'errors' => ['payload.rejection_reason' => ['The rejection reason field is required.']],
                ], 422);
            }
        }
        if (AssetBulkAction::RENAME_ASSETS->value === $action) {
            if (count($validated['asset_ids']) < 2) {
                return response()->json([
                    'message' => 'Select at least two assets for batch rename.',
                    'errors' => ['asset_ids' => ['At least two assets are required.']],
                ], 422);
            }
            $request->validate([
                'payload.base_name' => 'required|string|max:200',
            ]);
        }
        if (AssetBulkAction::ASSIGN_CATEGORY->value === $action) {
            $categoryId = $payload['category_id'] ?? null;
            if ($categoryId === null || (is_string($categoryId) && trim($categoryId) === '')) {
                return response()->json([
                    'message' => 'Category is required for ASSIGN_CATEGORY action.',
                    'errors' => ['payload.category_id' => ['The category_id field is required.']],
                ], 422);
            }
            $assetTypePayload = $payload['asset_type'] ?? null;
            if ($assetTypePayload !== null && $assetTypePayload !== '') {
                $allowed = ['asset', 'deliverable', 'ai_generated'];
                if (! in_array((string) $assetTypePayload, $allowed, true)) {
                    return response()->json([
                        'message' => 'asset_type must be asset, deliverable, or ai_generated.',
                        'errors' => ['payload.asset_type' => ['Invalid asset_type.']],
                    ], 422);
                }
            }
        }

        $actionEnum = AssetBulkAction::from($action);
        if ($actionEnum->isMetadataAction()) {
            $opType = $actionEnum->metadataOperationType();
            $metadata = $payload['metadata'] ?? [];
            if ($opType !== 'clear' && empty($metadata)) {
                return response()->json([
                    'message' => 'Metadata payload is required for METADATA_ADD and METADATA_REPLACE.',
                    'errors' => ['payload.metadata' => ['The metadata field is required.']],
                ], 422);
            }
        }

        try {
            $result = $this->bulkActionService->execute(
                $validated['asset_ids'],
                $action,
                $payload,
                $user,
                $tenant->id,
                $brand?->id
            );

            return response()->json($result->toArray());
        } catch (\InvalidArgumentException $e) {
            Log::info('[AssetBulkActionController] Validation error', ['message' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (AuthorizationException $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'You do not have permission to perform this action.',
            ], 403);
        } catch (\Throwable $e) {
            Log::error('[AssetBulkActionController] Bulk action failed', [
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Bulk action failed. Please try again.'], 500);
        }
    }
}
