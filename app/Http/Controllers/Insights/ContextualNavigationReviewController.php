<?php

namespace App\Http\Controllers\Insights;

use App\Http\Controllers\Controller;
use App\Jobs\RunContextualNavigationInsightsJob;
use App\Models\Category;
use App\Models\ContextualNavigationRecommendation;
use App\Models\MetadataField;
use App\Services\ContextualNavigation\ContextualNavigationApprovalService;
use App\Services\TenantPermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Phase 6 — admin-facing API for the Insights → Review “Contextual
 * Navigation” tab.
 *
 * Endpoints (all under /app/api/ai/review):
 *   GET  /contextual                          list pending recommendations
 *   POST /contextual/{id}/approve             approve + mutate via assignment service
 *   POST /contextual/{id}/reject              reject only
 *   POST /contextual/{id}/defer               defer only
 *   POST /contextual/run                      manual run trigger (force=1 bypasses cooldown)
 *
 * Permission model:
 *   - List/view: same as the existing AI review queue (metadata.suggestions.view).
 *   - Approve/reject/defer: requires either
 *       metadata.suggestions.apply OR metadata.tenant.visibility.manage.
 *   - Run trigger: tenant.visibility.manage (admin-level — kicks off a job).
 */
class ContextualNavigationReviewController extends Controller
{
    public function __construct(
        protected ContextualNavigationApprovalService $approvals,
    ) {}

    public function list(Request $request): JsonResponse
    {
        $ctx = $this->resolveContext($request, view: true);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$tenant, $brand, $user] = $ctx;

        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $statuses = $request->query('status');
        $statuses = is_string($statuses) && $statuses !== ''
            ? array_values(array_unique(array_filter(array_map('trim', explode(',', $statuses)))))
            : [ContextualNavigationRecommendation::STATUS_PENDING];

        $q = ContextualNavigationRecommendation::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', $statuses)
            ->orderByDesc('score')
            ->orderByDesc('updated_at');

        $total = (clone $q)->count();
        $rows = $q->offset(($page - 1) * $perPage)->limit($perPage)->get();

        // Eager-load related labels in two cheap lookups instead of N+1.
        $folderIds = $rows->pluck('category_id')->filter()->unique()->all();
        $fieldIds = $rows->pluck('metadata_field_id')->filter()->unique()->all();
        $folders = $folderIds === []
            ? collect()
            : Category::query()->whereIn('id', $folderIds)->get(['id', 'name', 'slug', 'brand_id'])->keyBy('id');
        $fields = $fieldIds === []
            ? collect()
            : MetadataField::query()->whereIn('id', $fieldIds)->get(['id', 'key', 'system_label'])->keyBy('id');

        $items = $rows->map(function (ContextualNavigationRecommendation $r) use ($folders, $fields) {
            $folder = $r->category_id ? $folders->get($r->category_id) : null;
            $field = $r->metadata_field_id ? $fields->get($r->metadata_field_id) : null;

            return [
                'id' => (int) $r->id,
                'recommendation_type' => $r->recommendation_type,
                'status' => $r->status,
                'source' => $r->source,
                'score' => $r->score !== null ? (float) $r->score : null,
                'confidence' => $r->confidence !== null ? (float) $r->confidence : null,
                'reason_summary' => $r->reason_summary,
                'metrics' => $r->metrics,
                'last_seen_at' => optional($r->last_seen_at)->toIso8601String(),
                'created_at' => optional($r->created_at)->toIso8601String(),
                'updated_at' => optional($r->updated_at)->toIso8601String(),
                'reviewed_at' => optional($r->reviewed_at)->toIso8601String(),
                'is_actionable' => $r->isActionable(),
                'folder' => $folder ? [
                    'id' => (int) $folder->id,
                    'name' => $folder->name,
                    'slug' => $folder->slug,
                ] : null,
                'field' => $field ? [
                    'id' => (int) $field->id,
                    'key' => $field->key,
                    'label' => $field->system_label ?? $field->key,
                ] : null,
            ];
        })->values();

        return response()->json([
            'items' => $items,
            'total' => $total,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => $total > 0 ? (int) ceil($total / $perPage) : 1,
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $ctx = $this->resolveContext($request, write: true);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$tenant, $brand, $user] = $ctx;

        $rec = ContextualNavigationRecommendation::query()
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $rec) {
            return response()->json(['message' => 'Recommendation not found.'], 404);
        }

        $notes = (string) $request->input('notes', '');
        $notes = $notes !== '' ? mb_substr($notes, 0, 1000) : null;

        try {
            $updated = $this->approvals->approve($rec, $tenant, $user, $notes);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'recommendation' => $this->serialize($updated)]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->finaliseSimple($request, $id, 'reject');
    }

    public function defer(Request $request, int $id): JsonResponse
    {
        return $this->finaliseSimple($request, $id, 'defer');
    }

    public function run(Request $request): JsonResponse
    {
        $ctx = $this->resolveContext($request, write: true, runTrigger: true);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$tenant, $brand, $user] = $ctx;

        $force = (bool) $request->input('force', false);
        RunContextualNavigationInsightsJob::dispatch($tenant->id, $force);

        return response()->json([
            'ok' => true,
            'message' => 'Contextual navigation analysis queued.',
            'tenant_id' => (int) $tenant->id,
            'force' => $force,
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function finaliseSimple(Request $request, int $id, string $action): JsonResponse
    {
        $ctx = $this->resolveContext($request, write: true);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$tenant, $brand, $user] = $ctx;

        $rec = ContextualNavigationRecommendation::query()
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $rec) {
            return response()->json(['message' => 'Recommendation not found.'], 404);
        }

        $notes = (string) $request->input('notes', '');
        $notes = $notes !== '' ? mb_substr($notes, 0, 1000) : null;

        try {
            $updated = match ($action) {
                'reject' => $this->approvals->reject($rec, $tenant, $user, $notes),
                'defer' => $this->approvals->defer($rec, $tenant, $user, $notes),
                default => throw new InvalidArgumentException('Unknown action.'),
            };
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'recommendation' => $this->serialize($updated)]);
    }

    /**
     * Resolve tenant + brand + user, plus permission gate.
     *
     * @return array{0:\App\Models\Tenant,1:\App\Models\Brand,2:\App\Models\User}|JsonResponse
     */
    private function resolveContext(
        Request $request,
        bool $view = false,
        bool $write = false,
        bool $runTrigger = false,
    ): array|JsonResponse {
        $tenant = app('tenant');
        $brand = app()->bound('brand') ? app('brand') : null;
        $user = Auth::user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['message' => 'Tenant and brand must be selected'], 403);
        }

        $resolver = app(TenantPermissionResolver::class);

        if ($view) {
            $isContributor = strtolower((string) $user->getRoleForBrand($brand)) === 'contributor';
            $canViewAll = ! $isContributor && $resolver->hasForBrand($user, $brand, 'metadata.suggestions.view');
            $canReviewOthers = $isContributor && $resolver->hasForBrand($user, $brand, 'metadata.review_candidates');
            $canManage = (bool) $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');
            if (! $canViewAll && ! $canReviewOthers && ! $canManage) {
                return response()->json(['message' => 'Permission denied'], 403);
            }
        }
        if ($write) {
            $canApply = $resolver->hasForBrand($user, $brand, 'metadata.suggestions.apply')
                || (bool) $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');
            if (! $canApply) {
                return response()->json(['message' => 'Permission denied'], 403);
            }
        }
        if ($runTrigger) {
            // Run trigger is admin-only — it kicks off a job that may
            // hit the AI agent if reasoning is enabled.
            $canManage = (bool) $user->hasPermissionForTenant($tenant, 'metadata.tenant.visibility.manage');
            if (! $canManage) {
                return response()->json(['message' => 'Permission denied'], 403);
            }
        }

        return [$tenant, $brand, $user];
    }

    private function serialize(ContextualNavigationRecommendation $r): array
    {
        return [
            'id' => (int) $r->id,
            'recommendation_type' => $r->recommendation_type,
            'status' => $r->status,
            'source' => $r->source,
            'score' => $r->score !== null ? (float) $r->score : null,
            'confidence' => $r->confidence !== null ? (float) $r->confidence : null,
            'reason_summary' => $r->reason_summary,
            'metrics' => $r->metrics,
            'reviewed_at' => optional($r->reviewed_at)->toIso8601String(),
            'reviewer_notes' => $r->reviewer_notes,
        ];
    }
}
