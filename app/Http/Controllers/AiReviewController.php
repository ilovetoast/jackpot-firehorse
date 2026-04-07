<?php

namespace App\Http\Controllers;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\Category;
use App\Services\AI\Insights\AiInsightSuggestionActionService;
use App\Services\AI\Insights\AiSuggestionSuppressionService;
use App\Services\AI\Insights\InsightSuggestionReason;
use App\Services\FeatureGate;
use App\Services\TenantPermissionResolver;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use App\Support\Metadata\CategoryTypeResolver;
use App\Support\Roles\PermissionMap;
use App\Support\ThumbnailMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI Review Workspace — split view for tag and category suggestions.
 * GET /app/insights/review — page
 * GET /app/api/ai/review?type=tags|categories — data
 */
class AiReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = (string) $request->query('tab', 'tags');
        $allowed = ['tags', 'categories', 'values', 'fields'];
        if (! in_array($tab, $allowed, true)) {
            $tab = 'tags';
        }

        $user = $request->user();
        $tenant = app('tenant');
        $brand = app()->bound('brand') ? app('brand') : null;

        $canCreateFieldFromSuggestion = false;
        if ($user && $tenant) {
            $canCreateFieldFromSuggestion = $this->canCreateFieldFromSuggestion($user, $tenant);
        }

        $canViewAi = $this->userCanViewAiSuggestions($user, $brand);
        $canViewUploadApprovals = false;
        $creatorModuleEnabled = false;
        if ($user && $tenant && $brand) {
            $featureGate = app(FeatureGate::class);
            if ($featureGate->approvalsEnabled($tenant)) {
                $brandRole = $user->getRoleForBrand($brand);
                $canViewUploadApprovals = (bool) ($brandRole && PermissionMap::canApproveAssets($brandRole));
            }
            $creatorModuleEnabled = $featureGate->creatorModuleEnabled($tenant);
        }

        $requestedWorkspace = (string) $request->query('workspace', '');
        if ($requestedWorkspace !== '' && ! in_array($requestedWorkspace, ['ai', 'uploads'], true)) {
            $requestedWorkspace = '';
        }

        if ($requestedWorkspace === '') {
            $workspace = $canViewAi ? 'ai' : ($canViewUploadApprovals ? 'uploads' : 'ai');
        } else {
            $workspace = $requestedWorkspace;
        }

        if ($workspace === 'ai' && ! $canViewAi && $canViewUploadApprovals) {
            $workspace = 'uploads';
        }
        if ($workspace === 'uploads' && ! $canViewUploadApprovals && $canViewAi) {
            $workspace = 'ai';
        }

        $approvalQueue = (string) $request->query('approval_queue', 'team');
        if (! in_array($approvalQueue, ['team', 'creator'], true)) {
            $approvalQueue = 'team';
        }
        if (! $creatorModuleEnabled && $approvalQueue === 'creator') {
            $approvalQueue = 'team';
        }

        $reviewTabCounts = null;
        if ($canViewAi && $tenant && $brand && $user) {
            $reviewTabCounts = $this->reviewTabCountPayload($tenant, $brand, $user);
        }

        return Inertia::render('Insights/Review', [
            'initialTab' => $tab,
            'initialWorkspace' => $workspace,
            'initialApprovalQueue' => $approvalQueue,
            'canViewAi' => $canViewAi,
            'canViewUploadApprovals' => $canViewUploadApprovals,
            'creatorModuleEnabled' => $creatorModuleEnabled,
            'canCreateFieldFromSuggestion' => $canCreateFieldFromSuggestion,
            'reviewTabCounts' => $reviewTabCounts,
        ]);
    }

    private function userCanViewAiSuggestions($user, $brand): bool
    {
        if (! $user || ! $brand) {
            return false;
        }

        $resolver = app(TenantPermissionResolver::class);
        $isContributor = strtolower((string) $user->getRoleForBrand($brand)) === 'contributor';
        $canViewAll = ! $isContributor && $resolver->hasForBrand($user, $brand, 'metadata.suggestions.view');
        $canReviewOthers = $isContributor && $resolver->hasForBrand($user, $brand, 'metadata.review_candidates');

        return $canViewAll || $canReviewOthers;
    }

    public function data(Request $request, TenantPermissionResolver $resolver): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();
        $type = $request->query('type', 'tags');

        if (! $tenant || ! $brand) {
            return response()->json(['message' => 'Tenant and brand must be selected'], 403);
        }

        // Contributors keep metadata.suggestions.view in the permission map but must not see org-wide queues here.
        $isContributor = strtolower((string) $user->getRoleForBrand($brand)) === 'contributor';
        $canViewAll = ! $isContributor && $resolver->hasForBrand($user, $brand, 'metadata.suggestions.view');
        $canReviewOthers = $isContributor && $resolver->hasForBrand($user, $brand, 'metadata.review_candidates');
        if (! $canViewAll && ! $canReviewOthers) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        if ($type === 'tags') {
            return $this->getTagSuggestions($tenant, $brand, $user);
        }
        if ($type === 'categories') {
            return $this->getCategorySuggestions($tenant, $brand, $user);
        }
        if ($type === 'values') {
            return $this->getValueSuggestions($tenant, $brand, $request);
        }
        if ($type === 'fields') {
            return $this->getFieldSuggestions($tenant, $brand, $request);
        }

        return response()->json(['message' => 'Invalid type. Use tags, categories, values, or fields.'], 400);
    }

    /**
     * GET /app/api/ai/review/counts
     * Pending totals per Review tab (same visibility and suppression rules as list endpoints).
     */
    public function counts(Request $request, TenantPermissionResolver $resolver): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();

        if (! $tenant || ! $brand) {
            return response()->json(['message' => 'Tenant and brand must be selected'], 403);
        }

        $isContributor = strtolower((string) $user->getRoleForBrand($brand)) === 'contributor';
        $canViewAll = ! $isContributor && $resolver->hasForBrand($user, $brand, 'metadata.suggestions.view');
        $canReviewOthers = $isContributor && $resolver->hasForBrand($user, $brand, 'metadata.review_candidates');
        if (! $canViewAll && ! $canReviewOthers) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        return response()->json($this->reviewTabCountPayload($tenant, $brand, $user));
    }

    /**
     * Pending totals per AI Review sub-tab (tags / categories / values / fields).
     * Shared by JSON counts endpoint and Inertia Review page for SSR tab badges.
     *
     * @return array{tags: int, categories: int, values: int, fields: int}
     */
    protected function reviewTabCountPayload($tenant, $brand, $user): array
    {
        return [
            'tags' => $this->countTagSuggestions($tenant, $brand, $user),
            'categories' => $this->countCategorySuggestions($tenant, $brand, $user),
            'values' => $this->countValueSuggestionsPending($tenant),
            'fields' => $this->countFieldSuggestionsPending($tenant),
        ];
    }

    /**
     * POST /app/api/ai/review/value-suggestions/{id}/accept
     */
    public function acceptValueSuggestion(
        int $id,
        TenantPermissionResolver $resolver,
        AiInsightSuggestionActionService $actions
    ): JsonResponse {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['message' => 'Tenant and brand must be selected'], 403);
        }
        if (! $this->canApplySuggestions($resolver, $user, $brand)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        try {
            $actions->acceptValueSuggestion($id, $tenant);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /app/api/ai/review/value-suggestions/{id}/reject
     */
    public function rejectValueSuggestion(
        int $id,
        TenantPermissionResolver $resolver,
        AiInsightSuggestionActionService $actions
    ): JsonResponse {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['message' => 'Tenant and brand must be selected'], 403);
        }
        if (! $this->canDismissSuggestions($resolver, $user, $brand)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        if (! $actions->rejectValueSuggestion($id, $tenant)) {
            return response()->json(['message' => 'Suggestion not found or already handled'], 404);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /app/api/ai/review/field-suggestions/{id}/accept
     */
    public function acceptFieldSuggestion(
        int $id,
        TenantPermissionResolver $resolver,
        AiInsightSuggestionActionService $actions
    ): JsonResponse {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['message' => 'Tenant and brand must be selected'], 403);
        }
        if (! $this->canCreateFieldFromSuggestion($user, $tenant)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        try {
            $actions->acceptFieldSuggestion($id, $tenant, $brand);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'limit_type' => $e->limitType ?? null,
            ], 403);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /app/api/ai/review/field-suggestions/{id}/reject
     */
    public function rejectFieldSuggestion(
        int $id,
        TenantPermissionResolver $resolver,
        AiInsightSuggestionActionService $actions
    ): JsonResponse {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();
        if (! $tenant || ! $brand || ! $user) {
            return response()->json(['message' => 'Tenant and brand must be selected'], 403);
        }
        if (! $this->canDismissSuggestions($resolver, $user, $brand)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        if (! $actions->rejectFieldSuggestion($id, $tenant)) {
            return response()->json(['message' => 'Suggestion not found or already handled'], 404);
        }

        return response()->json(['ok' => true]);
    }

    protected function canApplySuggestions(TenantPermissionResolver $resolver, $user, $brand): bool
    {
        return $resolver->hasForBrand($user, $brand, 'metadata.suggestions.apply')
            || $resolver->hasForBrand($user, $brand, 'metadata.edit_post_upload');
    }

    protected function canDismissSuggestions(TenantPermissionResolver $resolver, $user, $brand): bool
    {
        return $resolver->hasForBrand($user, $brand, 'metadata.suggestions.dismiss')
            || $resolver->hasForBrand($user, $brand, 'metadata.edit_post_upload');
    }

    protected function canCreateFieldFromSuggestion($user, $tenant): bool
    {
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin'], true);

        return $isTenantOwnerOrAdmin
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.field.create')
            || $user->hasPermissionForTenant($tenant, 'metadata.tenant.field.manage');
    }

    /**
     * @return array{0: int, 1: int} per_page, page (clamped)
     */
    protected function reviewPaginationParams(Request $request, int $defaultPerPage = 50, int $maxPerPage = 100): array
    {
        $perPage = min(max((int) $request->query('per_page', $defaultPerPage), 1), $maxPerPage);
        $page = max((int) $request->query('page', 1), 1);

        return [$perPage, $page];
    }

    protected function paginationMeta(int $total, int $perPage, int $page): array
    {
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'total' => $total,
            'current_page' => $page,
            'last_page' => max(1, $lastPage),
            'per_page' => $perPage,
        ];
    }

    protected function getValueSuggestions($tenant, $brand, Request $request): JsonResponse
    {
        [$perPage, $page] = $this->reviewPaginationParams($request);
        // Fetch enough rows for suppression filter + later pages (cap avoids unbounded memory).
        $sqlLimit = min(20000, max($page * $perPage * 8, 1000));

        $rows = DB::table('ai_metadata_value_suggestions as amvs')
            ->where('amvs.tenant_id', $tenant->id)
            ->where('amvs.status', 'pending')
            ->orderByDesc('amvs.priority_score')
            ->orderByDesc('amvs.confidence')
            ->orderByDesc('amvs.supporting_asset_count')
            ->select(
                'amvs.id',
                'amvs.field_key',
                'amvs.suggested_value',
                'amvs.supporting_asset_count',
                'amvs.confidence',
                'amvs.priority_score',
                'amvs.consistency_score',
                'amvs.source'
            )
            ->limit($sqlLimit)
            ->get();

        $suppressed = $this->suppressedNormalizedKeys($tenant->id, 'value');
        $rows = $rows->filter(function ($r) use ($suppressed) {
            $k = AiSuggestionSuppressionService::normalizeValueKey((string) $r->field_key, (string) $r->suggested_value);

            return ! isset($suppressed[$k]);
        })->values();

        $total = $rows->count();
        $rows = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        $fieldKeys = $rows->pluck('field_key')->unique()->filter()->values()->all();
        $labels = [];
        if ($fieldKeys !== []) {
            $fieldRows = DB::table('metadata_fields')
                ->whereNull('deprecated_at')
                ->whereIn('key', $fieldKeys)
                ->where(function ($q) use ($tenant) {
                    $q->where(function ($q2) use ($tenant) {
                        $q2->where('scope', 'tenant')
                            ->where('tenant_id', $tenant->id);
                    })->orWhere(function ($q2) {
                        $q2->where('scope', 'system')
                            ->whereNull('tenant_id');
                    });
                })
                ->get(['key', 'system_label']);
            foreach ($fieldRows as $f) {
                $labels[$f->key] = $f->system_label ?? $f->key;
            }
        }

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id' => (int) $r->id,
                'type' => 'value_suggestion',
                'field_key' => $r->field_key,
                'field_label' => $labels[$r->field_key] ?? $r->field_key,
                'suggested_value' => $r->suggested_value,
                'supporting_asset_count' => (int) $r->supporting_asset_count,
                'confidence' => $r->confidence !== null ? (float) $r->confidence : null,
                'priority_score' => $r->priority_score !== null ? (float) $r->priority_score : null,
                'consistency_score' => isset($r->consistency_score) && $r->consistency_score !== null ? (float) $r->consistency_score : null,
                'source' => $r->source,
                'reason' => InsightSuggestionReason::forValueSuggestion($r),
            ];
        }

        return response()->json(array_merge(
            ['items' => $items],
            $this->paginationMeta($total, $perPage, $page)
        ));
    }

    protected function getFieldSuggestions($tenant, $brand, Request $request): JsonResponse
    {
        [$perPage, $page] = $this->reviewPaginationParams($request);
        $sqlLimit = min(20000, max($page * $perPage * 8, 1000));

        $rows = DB::table('ai_metadata_field_suggestions as amfs')
            ->where('amfs.tenant_id', $tenant->id)
            ->where('amfs.status', 'pending')
            ->orderByDesc('amfs.priority_score')
            ->orderByDesc('amfs.confidence')
            ->orderByDesc('amfs.supporting_asset_count')
            ->select(
                'amfs.id',
                'amfs.category_slug',
                'amfs.field_name',
                'amfs.field_key',
                'amfs.suggested_options',
                'amfs.supporting_asset_count',
                'amfs.confidence',
                'amfs.priority_score',
                'amfs.consistency_score',
                'amfs.source_cluster'
            )
            ->limit($sqlLimit)
            ->get();

        $suppressed = $this->suppressedNormalizedKeys($tenant->id, 'field');
        $rows = $rows->filter(function ($r) use ($suppressed) {
            $k = AiSuggestionSuppressionService::normalizeFieldKey(
                (string) $r->category_slug,
                (string) $r->field_key,
                (string) $r->source_cluster
            );

            return ! isset($suppressed[$k]);
        })->values();

        $total = $rows->count();
        $rows = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        $countsBySlug = $this->countAssetsInCategoryBySlugForBrand($tenant->id, (int) $brand->id);

        $items = [];
        foreach ($rows as $r) {
            $opts = $r->suggested_options;
            if (is_string($opts)) {
                $opts = json_decode($opts, true);
            }
            if (! is_array($opts)) {
                $opts = [];
            }

            $category = Category::query()
                ->where('tenant_id', $tenant->id)
                ->where('brand_id', $brand->id)
                ->where('slug', $r->category_slug)
                ->whereNull('deleted_at')
                ->select('name')
                ->first();

            $catTotal = (int) ($countsBySlug[$r->category_slug] ?? 0);

            $items[] = [
                'id' => (int) $r->id,
                'type' => 'field_suggestion',
                'category_slug' => $r->category_slug,
                'category_name' => $category?->name,
                'field_name' => $r->field_name,
                'field_key' => $r->field_key,
                'suggested_options' => array_values($opts),
                'supporting_asset_count' => (int) $r->supporting_asset_count,
                'confidence' => $r->confidence !== null ? (float) $r->confidence : null,
                'priority_score' => $r->priority_score !== null ? (float) $r->priority_score : null,
                'consistency_score' => isset($r->consistency_score) && $r->consistency_score !== null ? (float) $r->consistency_score : null,
                'source_cluster' => $r->source_cluster,
                'reason' => InsightSuggestionReason::forFieldSuggestion($r, $catTotal, $category?->name),
            ];
        }

        return response()->json(array_merge(
            ['items' => $items],
            $this->paginationMeta($total, $perPage, $page)
        ));
    }

    /**
     * @return array<string, true> normalized_key => true
     */
    protected function suppressedNormalizedKeys(int $tenantId, string $suggestionType): array
    {
        $rows = DB::table('ai_suggestion_feedback')
            ->where('tenant_id', $tenantId)
            ->where('suggestion_type', $suggestionType)
            ->where('rejected_count', '>=', AiSuggestionSuppressionService::REJECT_THRESHOLD)
            ->pluck('normalized_key');

        $map = [];
        foreach ($rows as $k) {
            $map[(string) $k] = true;
        }

        return $map;
    }

    /**
     * @return array<string, int> category slug => asset count
     */
    protected function countAssetsInCategoryBySlugForBrand(int $tenantId, int $brandId): array
    {
        $categories = Category::query()
            ->where('tenant_id', $tenantId)
            ->where('brand_id', $brandId)
            ->whereNull('deleted_at')
            ->select('id', 'slug')
            ->get();

        $out = [];
        foreach ($categories as $cat) {
            $out[$cat->slug] = (int) DB::table('assets')
                ->where('tenant_id', $tenantId)
                ->where('brand_id', $brandId)
                ->whereNull('deleted_at')
                ->where('metadata->category_id', (int) $cat->id)
                ->count();
        }

        return $out;
    }

    /**
     * Human-readable tag label when DB stored JSON (e.g. '["high_rise_leggings"]') as plain text.
     */
    protected function normalizeTagCandidateLabel(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }
        $first = $trimmed[0] ?? '';
        if ($first !== '[' && $first !== '{') {
            return $raw;
        }
        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $raw;
        }
        if (is_array($decoded)) {
            $parts = [];
            foreach ($decoded as $v) {
                if (is_string($v)) {
                    $parts[] = $v;
                } elseif (is_scalar($v)) {
                    $parts[] = (string) $v;
                }
            }

            return $parts !== [] ? implode(', ', $parts) : $raw;
        }
        if (is_string($decoded)) {
            return $decoded;
        }

        return $raw;
    }

    protected function buildTagSuggestionsBaseQuery($tenant, $brand, $user)
    {
        $q = DB::table('asset_tag_candidates')
            ->join('assets', 'asset_tag_candidates.asset_id', '=', 'assets.id')
            ->whereNull('assets.deleted_at')
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
            );
        app(\App\Services\AiReviewSuggestionScopeService::class)->scopeQueryToAiReviewAssetVisibility($q, $user, $brand);

        return $q;
    }

    protected function buildCategorySuggestionsBaseQuery($tenant, $brand, $user)
    {
        $q = DB::table('asset_metadata_candidates')
            ->join('assets', 'asset_metadata_candidates.asset_id', '=', 'assets.id')
            ->join('metadata_fields', 'asset_metadata_candidates.metadata_field_id', '=', 'metadata_fields.id')
            ->whereNull('assets.deleted_at')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->whereNull('asset_metadata_candidates.resolved_at')
            ->whereNull('asset_metadata_candidates.dismissed_at')
            ->where('asset_metadata_candidates.producer', 'ai')
            ->select(
                'asset_metadata_candidates.id',
                'asset_metadata_candidates.asset_id',
                'asset_metadata_candidates.metadata_field_id',
                'asset_metadata_candidates.value_json',
                'asset_metadata_candidates.confidence',
                'asset_metadata_candidates.source',
                'metadata_fields.key as field_key',
                'metadata_fields.system_label as field_label',
                'metadata_fields.type as field_type',
                'assets.title as asset_title',
                'assets.original_filename as asset_filename',
                'assets.thumbnail_status',
                'assets.metadata'
            );
        app(\App\Services\AiReviewSuggestionScopeService::class)->scopeQueryToAiReviewAssetVisibility($q, $user, $brand);

        return $q;
    }

    protected function countTagSuggestions($tenant, $brand, $user): int
    {
        $q = $this->buildTagSuggestionsBaseQuery($tenant, $brand, $user);

        return (int) (clone $q)->count();
    }

    protected function countCategorySuggestions($tenant, $brand, $user): int
    {
        $q = $this->buildCategorySuggestionsBaseQuery($tenant, $brand, $user);

        return (int) (clone $q)->count();
    }

    protected function countValueSuggestionsPending($tenant): int
    {
        $sqlLimit = 20000;
        $rows = DB::table('ai_metadata_value_suggestions as amvs')
            ->where('amvs.tenant_id', $tenant->id)
            ->where('amvs.status', 'pending')
            ->orderByDesc('amvs.priority_score')
            ->orderByDesc('amvs.confidence')
            ->orderByDesc('amvs.supporting_asset_count')
            ->select(
                'amvs.field_key',
                'amvs.suggested_value'
            )
            ->limit($sqlLimit)
            ->get();

        $suppressed = $this->suppressedNormalizedKeys($tenant->id, 'value');

        return (int) $rows->filter(function ($r) use ($suppressed) {
            $k = AiSuggestionSuppressionService::normalizeValueKey((string) $r->field_key, (string) $r->suggested_value);

            return ! isset($suppressed[$k]);
        })->count();
    }

    protected function countFieldSuggestionsPending($tenant): int
    {
        $sqlLimit = 20000;
        $rows = DB::table('ai_metadata_field_suggestions as amfs')
            ->where('amfs.tenant_id', $tenant->id)
            ->where('amfs.status', 'pending')
            ->orderByDesc('amfs.priority_score')
            ->orderByDesc('amfs.confidence')
            ->orderByDesc('amfs.supporting_asset_count')
            ->select(
                'amfs.category_slug',
                'amfs.field_key',
                'amfs.source_cluster'
            )
            ->limit($sqlLimit)
            ->get();

        $suppressed = $this->suppressedNormalizedKeys($tenant->id, 'field');

        return (int) $rows->filter(function ($r) use ($suppressed) {
            $k = AiSuggestionSuppressionService::normalizeFieldKey(
                (string) $r->category_slug,
                (string) $r->field_key,
                (string) $r->source_cluster
            );

            return ! isset($suppressed[$k]);
        })->count();
    }

    protected function getTagSuggestions($tenant, $brand, $user): JsonResponse
    {
        $q = $this->buildTagSuggestionsBaseQuery($tenant, $brand, $user);

        [$perPage, $page] = $this->reviewPaginationParams(request());
        $total = (clone $q)->count();
        $candidates = $q
            ->orderByDesc('asset_tag_candidates.confidence')
            ->orderByDesc('asset_tag_candidates.created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return $this->formatTagResponse($candidates, $this->paginationMeta($total, $perPage, $page));
    }

    protected function getCategorySuggestions($tenant, $brand, $user): JsonResponse
    {
        $q = $this->buildCategorySuggestionsBaseQuery($tenant, $brand, $user);

        [$perPage, $page] = $this->reviewPaginationParams(request());
        $total = (clone $q)->count();
        $candidates = $q
            ->orderByDesc('asset_metadata_candidates.confidence')
            ->orderByDesc('asset_metadata_candidates.created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $fieldIds = $candidates->pluck('metadata_field_id')->unique();
        $optionsMap = [];
        if ($fieldIds->isNotEmpty()) {
            $options = DB::table('metadata_options')
                ->whereIn('metadata_field_id', $fieldIds)
                ->select('metadata_field_id', 'value', 'system_label as display_label')
                ->get()
                ->groupBy('metadata_field_id');
            foreach ($options as $fieldId => $opts) {
                $optionsMap[$fieldId] = $opts->map(fn ($opt) => [
                    'value' => $opt->value,
                    'display_label' => $opt->display_label,
                ])->toArray();
            }
        }

        return $this->formatCategoryResponse($candidates, $optionsMap, $this->paginationMeta($total, $perPage, $page));
    }

    protected function formatTagResponse($candidates, array $paginationMeta): JsonResponse
    {
        $assetIds = $candidates->pluck('asset_id')->unique()->all();
        $assets = Asset::whereIn('id', $assetIds)->get()->keyBy('id');
        $categoryIds = $assets->pluck('metadata')->map(fn ($m) => (is_array($m ?? null) ? ($m['category_id'] ?? null) : null))->filter()->unique()->values()->all();
        $categories = $categoryIds ? Category::whereIn('id', $categoryIds)->get()->keyBy('id') : collect();

        $items = [];
        foreach ($candidates as $c) {
            $asset = $assets->get($c->asset_id);
            $thumbnailUrls = $this->getThumbnailUrls($asset);
            $metadata = $asset ? ($asset->metadata ?? []) : [];
            $categoryId = $metadata['category_id'] ?? null;
            $tagLabel = $this->normalizeTagCandidateLabel((string) $c->value);

            $items[] = [
                'id' => $c->id,
                'asset_id' => $c->asset_id,
                'type' => 'tag',
                'suggestion' => $tagLabel,
                'value' => $c->value,
                'confidence' => $c->confidence ? (float) $c->confidence : null,
                'asset_title' => $c->asset_title,
                'asset_filename' => $c->asset_filename,
                'asset_category' => $categoryId ? ($categories->get($categoryId)?->name ?? null) : null,
                'thumbnail_url' => $thumbnailUrls['final'] ?? $thumbnailUrls['preview'] ?? null,
                'thumbnail_status' => $asset ? ($asset->thumbnail_status ?? 'pending') : 'pending',
            ];
        }

        return response()->json(array_merge(
            ['items' => $items],
            $paginationMeta
        ));
    }

    protected function formatCategoryResponse($candidates, array $optionsMap, array $paginationMeta): JsonResponse
    {
        $assetIds = $candidates->pluck('asset_id')->unique()->all();
        $assets = Asset::whereIn('id', $assetIds)->get()->keyBy('id');
        $categoryIds = $assets->pluck('metadata')->map(fn ($m) => (is_array($m ?? null) ? ($m['category_id'] ?? null) : null))->filter()->unique()->values()->all();
        $categories = $categoryIds ? Category::whereIn('id', $categoryIds)->get()->keyBy('id') : collect();

        $items = [];
        foreach ($candidates as $c) {
            $asset = $assets->get($c->asset_id);
            $thumbnailUrls = $this->getThumbnailUrls($asset);
            $value = json_decode($c->value_json, true);
            $metadata = $asset ? ($asset->metadata ?? []) : [];
            $categoryId = $metadata['category_id'] ?? null;
            $category = $categoryId ? ($categories->get($categoryId) ?? null) : null;
            $typeResolved = CategoryTypeResolver::resolve($category?->slug);
            $isTypeField = $typeResolved && $typeResolved['field_key'] === $c->field_key;
            $fieldDisplayLabel = $isTypeField ? 'Type' : $c->field_label;
            $sectionHeader = $isTypeField
                ? ($category?->name ? 'Type · '.$category->name : 'Type')
                : $c->field_label;

            $displayValue = is_array($value) ? ($value['value'] ?? $value['id'] ?? json_encode($value)) : (string) $value;
            if (isset($optionsMap[$c->metadata_field_id])) {
                foreach ($optionsMap[$c->metadata_field_id] as $opt) {
                    if (($opt['value'] ?? null) == $displayValue) {
                        $displayValue = $opt['display_label'] ?? $displayValue;
                        break;
                    }
                }
            }

            $items[] = [
                'id' => $c->id,
                'asset_id' => $c->asset_id,
                'type' => 'metadata_candidate',
                'suggestion' => $displayValue,
                'field_key' => $c->field_key,
                'field_label' => $c->field_label,
                'field_display_label' => $fieldDisplayLabel,
                'section_header' => $sectionHeader,
                'field_type' => $c->field_type,
                'value' => $value,
                'confidence' => $c->confidence ? (float) $c->confidence : null,
                'asset_title' => $c->asset_title,
                'asset_filename' => $c->asset_filename,
                'asset_category' => $category?->name,
                'thumbnail_url' => $thumbnailUrls['final'] ?? $thumbnailUrls['preview'] ?? null,
                'thumbnail_status' => $asset ? ($asset->thumbnail_status ?? 'pending') : 'pending',
                'options' => $optionsMap[$c->metadata_field_id] ?? [],
            ];
        }

        return response()->json(array_merge(
            ['items' => $items],
            $paginationMeta
        ));
    }

    protected function getThumbnailUrls(?Asset $asset): array
    {
        if (! $asset) {
            return ['preview' => null, 'final' => null];
        }
        $metadata = $asset->metadata ?? [];
        $thumbnailStatus = $asset->thumbnail_status instanceof ThumbnailStatus
            ? $asset->thumbnail_status->value
            : ($asset->thumbnail_status ?? 'pending');

        $preview = $asset->deliveryUrl(AssetVariant::THUMB_PREVIEW, DeliveryContext::AUTHENTICATED) ?: null;
        $final = null;
        $thumbnailsExist = ThumbnailMetadata::hasMediumOrThumb($metadata);

        if ($thumbnailStatus === 'completed' || $thumbnailsExist) {
            $mediumPath = $asset->thumbnailPathForStyle('medium');
            $variant = $mediumPath ? AssetVariant::THUMB_MEDIUM : AssetVariant::THUMB_SMALL;
            $final = $asset->deliveryUrl($variant, DeliveryContext::AUTHENTICATED);
        }

        return ['preview' => $preview, 'final' => $final];
    }
}
