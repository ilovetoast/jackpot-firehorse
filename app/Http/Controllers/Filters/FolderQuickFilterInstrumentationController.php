<?php

namespace App\Http\Controllers\Filters;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MetadataField;
use App\Services\Filters\Contracts\QuickFilterInstrumentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 5.2 — fire-and-forget instrumentation endpoint for events the value
 * endpoint can't piggy-back on.
 *
 *   POST /api/tenant/folders/{category}/quick-filters/overflow-open
 *   POST /api/tenant/folders/{category}/quick-filters/{field}/selection
 *
 * Both endpoints:
 *   - return 204 No Content on success,
 *   - swallow auth/feature flag failures with the same 4xx contract used
 *     elsewhere in this controller cluster,
 *   - never throw on instrumentation errors (the binding's responsibility).
 *
 * The seam exists so frontend instrumentation calls never crash a flow even
 * if a real analytics sink is misconfigured later.
 */
class FolderQuickFilterInstrumentationController extends Controller
{
    public function __construct(
        protected QuickFilterInstrumentation $instrumentation,
    ) {}

    public function overflowOpen(Request $request, int $category): JsonResponse
    {
        [$tenant, $user, $error] = $this->resolveContext();
        if ($error) {
            return $error;
        }

        $categoryModel = Category::query()
            ->where('id', $category)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $categoryModel) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        $this->instrumentation->recordOverflowOpen($categoryModel, $tenant);

        return response()->json(null, 204);
    }

    public function selection(Request $request, int $category, int $field): JsonResponse
    {
        [$tenant, $user, $error] = $this->resolveContext();
        if ($error) {
            return $error;
        }

        $categoryModel = Category::query()
            ->where('id', $category)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $categoryModel) {
            return response()->json(['error' => 'Category not found'], 404);
        }
        $fieldModel = MetadataField::query()->where('id', $field)->first();
        if (! $fieldModel) {
            return response()->json(['error' => 'Field not found'], 404);
        }

        // Validate but be permissive — clients send the canonical filter
        // payload shape; we record the value as-is for analytics. The
        // record path itself swallows errors.
        $value = $request->input('value');

        $this->instrumentation->recordSelection($fieldModel, $categoryModel, $value, $tenant);

        return response()->json(null, 204);
    }

    /**
     * Shared auth context resolver. Returns either `[Tenant, User, null]` on
     * success, or `[null, null, JsonResponse]` carrying the error response so
     * each handler can early-return.
     *
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
        if (! $user->belongsToTenant($tenant->id)) {
            return [null, null, response()->json(['error' => 'User does not belong to this tenant'], 403)];
        }
        if (! (bool) config('categories.folder_quick_filters.enabled', false)) {
            return [null, null, response()->json(['error' => 'feature_disabled'], 422)];
        }

        return [$tenant, $user, null];
    }
}
