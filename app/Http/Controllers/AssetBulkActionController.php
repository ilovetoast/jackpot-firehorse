<?php

namespace App\Http\Controllers;

use App\Enums\AssetBulkAction;
use App\Services\Assets\BulkActionService;
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
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        $validated = $request->validate([
            'asset_ids' => 'required|array|min:1',
            'asset_ids.*' => 'required|uuid|exists:assets,id',
            'action' => ['required', 'string', Rule::in(array_map(fn ($c) => $c->value, AssetBulkAction::cases()))],
            'payload' => 'nullable|array',
        ]);

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
