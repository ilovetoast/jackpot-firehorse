<?php

namespace App\Http\Controllers\Hygiene;

use App\Http\Controllers\Controller;
use App\Models\MetadataField;
use App\Services\Hygiene\MetadataCanonicalizationService;
use App\Services\Hygiene\MetadataDuplicateDetector;
use App\Services\Hygiene\MetadataValueMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Phase 5.3 — admin endpoints for the metadata hygiene panel.
 *
 *   GET    /api/tenant/metadata/fields/{field}/hygiene/aliases
 *   POST   /api/tenant/metadata/fields/{field}/hygiene/aliases
 *   DELETE /api/tenant/metadata/fields/{field}/hygiene/aliases/{aliasId}
 *   GET    /api/tenant/metadata/fields/{field}/hygiene/duplicates
 *   POST   /api/tenant/metadata/fields/{field}/hygiene/merge
 *   GET    /api/tenant/metadata/fields/{field}/hygiene/merges        (history)
 *
 * Permission: same `metadata.tenant.visibility.manage` we use for the rest
 * of the metadata-management surface. Endpoints share a small auth helper
 * so the gate vocabulary stays consistent.
 *
 * No new routes write asset data without admin permission. Every route is
 * tenant-scoped (`app('tenant')`) and refuses unknown fields with 404.
 */
class MetadataHygieneController extends Controller
{
    public function __construct(
        protected MetadataCanonicalizationService $canonical,
        protected MetadataDuplicateDetector $duplicates,
        protected MetadataValueMergeService $merger,
    ) {}

    public function listAliases(Request $request, int $field): JsonResponse
    {
        [$tenant, $user, $error] = $this->resolveContext();
        if ($error) {
            return $error;
        }
        $fieldModel = $this->resolveField($field, $tenant);
        if (! $fieldModel) {
            return response()->json(['error' => 'Field not found'], 404);
        }

        $rows = $this->canonical->listForField($fieldModel, $tenant);

        return response()->json([
            'field' => [
                'id' => $fieldModel->id,
                'key' => $fieldModel->key,
                'label' => $fieldModel->system_label,
                'type' => $fieldModel->type,
            ],
            'aliases' => $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'alias_value' => (string) $r->alias_value,
                'canonical_value' => (string) $r->canonical_value,
                'source' => (string) $r->source,
                'created_by_user_id' => $r->created_by_user_id,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function addAlias(Request $request, int $field): JsonResponse
    {
        [$tenant, $user, $error] = $this->resolveContext();
        if ($error) {
            return $error;
        }
        $fieldModel = $this->resolveField($field, $tenant);
        if (! $fieldModel) {
            return response()->json(['error' => 'Field not found'], 404);
        }

        $validated = $request->validate([
            'alias' => 'required|string|max:255',
            'canonical' => 'required|string|max:255',
            'notes' => 'sometimes|nullable|string|max:1024',
        ]);

        try {
            $row = $this->canonical->addAlias(
                $fieldModel,
                $tenant,
                $validated['alias'],
                $validated['canonical'],
                $user,
                MetadataCanonicalizationService::SOURCE_MANUAL,
                $validated['notes'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => 'invalid_alias',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'alias' => [
                'id' => (int) $row->id,
                'alias_value' => (string) $row->alias_value,
                'canonical_value' => (string) $row->canonical_value,
                'source' => (string) $row->source,
                'created_at' => $row->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function removeAlias(Request $request, int $field, int $aliasId): JsonResponse
    {
        [$tenant, $user, $error] = $this->resolveContext();
        if ($error) {
            return $error;
        }
        $fieldModel = $this->resolveField($field, $tenant);
        if (! $fieldModel) {
            return response()->json(['error' => 'Field not found'], 404);
        }

        $row = \App\Models\MetadataValueAlias::query()
            ->where('id', $aliasId)
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $fieldModel->id)
            ->first();
        if (! $row) {
            return response()->json(['error' => 'Alias not found'], 404);
        }

        $this->canonical->removeAlias($fieldModel, $tenant, $row->alias_value);

        return response()->json(null, 204);
    }

    public function duplicateCandidates(Request $request, int $field): JsonResponse
    {
        [$tenant, $user, $error] = $this->resolveContext();
        if ($error) {
            return $error;
        }
        $fieldModel = $this->resolveField($field, $tenant);
        if (! $fieldModel) {
            return response()->json(['error' => 'Field not found'], 404);
        }

        $candidates = $this->duplicates->findCandidates($fieldModel, $tenant);

        return response()->json([
            'field' => [
                'id' => $fieldModel->id,
                'key' => $fieldModel->key,
                'label' => $fieldModel->system_label,
            ],
            'candidates' => $candidates,
            // Frontend uses this to render the "Showing first N" hint when
            // we hit the cap, in line with the value flyout's truncation
            // copy. Mirrors MetadataDuplicateDetector::MAX_CANDIDATES_RETURNED.
            'limit' => MetadataDuplicateDetector::MAX_CANDIDATES_RETURNED,
        ]);
    }

    public function merge(Request $request, int $field): JsonResponse
    {
        [$tenant, $user, $error] = $this->resolveContext();
        if ($error) {
            return $error;
        }
        $fieldModel = $this->resolveField($field, $tenant);
        if (! $fieldModel) {
            return response()->json(['error' => 'Field not found'], 404);
        }

        $validated = $request->validate([
            'from' => 'required|string|max:255',
            'to' => 'required|string|max:255',
            'remove_from_option' => 'sometimes|boolean',
            'notes' => 'sometimes|nullable|string|max:1024',
        ]);

        try {
            $result = $this->merger->merge(
                $fieldModel,
                $tenant,
                $validated['from'],
                $validated['to'],
                $user,
                (bool) ($validated['remove_from_option'] ?? false),
                $validated['notes'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => 'invalid_merge',
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }

    public function recentMerges(Request $request, int $field): JsonResponse
    {
        [$tenant, $user, $error] = $this->resolveContext();
        if ($error) {
            return $error;
        }
        $fieldModel = $this->resolveField($field, $tenant);
        if (! $fieldModel) {
            return response()->json(['error' => 'Field not found'], 404);
        }
        $limit = (int) $request->query('limit', 25);

        return response()->json([
            'merges' => $this->merger->recentMerges($fieldModel, $tenant, $limit),
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function resolveField(int $field, \App\Models\Tenant $tenant): ?MetadataField
    {
        $model = MetadataField::query()->where('id', $field)->first();
        if (! $model) {
            return null;
        }
        if (
            ! $model->is_internal_only
            && ($model->scope ?? null) === 'tenant'
            && (int) ($model->tenant_id ?? 0) !== (int) $tenant->id
        ) {
            return null;
        }

        return $model;
    }

    /**
     * @return array{0: \App\Models\Tenant|null, 1: \App\Models\User|null, 2: \Illuminate\Http\JsonResponse|null}
     */
    private function resolveContext(): array
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        $user = Auth::user();
        if (! $tenant) {
            return [null, null, response()->json(['error' => 'Tenant not found'], 404)];
        }
        if (! $user) {
            return [null, null, response()->json(['error' => 'Unauthenticated'], 401)];
        }
        if (! $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage')) {
            return [null, null, response()->json([
                'error' => 'You do not have permission to manage metadata visibility.',
            ], 403)];
        }

        return [$tenant, $user, null];
    }
}
