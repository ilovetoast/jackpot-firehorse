<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\HelpAiQuestion;
use App\Models\Tenant;
use App\Services\AuthPermissionService;
use App\Services\HelpActionService;
use App\Services\HelpActionVisibilityContext;
use App\Services\HelpAiAskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HelpActionController extends Controller
{
    public function index(Request $request, AuthPermissionService $authPermissionService, HelpActionService $helpActionService): JsonResponse
    {
        $user = $request->user();
        [$tenant, $brand] = $this->resolveWorkspaceFromAppOrSession($request);

        $permissions = $authPermissionService->effectivePermissions($user, $tenant, $brand);

        $visibilityContext = ($tenant instanceof Tenant && $user)
            ? new HelpActionVisibilityContext($user, $tenant, $brand)
            : null;

        $rawQ = $request->query('q');
        $query = is_string($rawQ) ? $rawQ : null;

        $rawRoute = $request->query('route_name');
        $contextRoute = is_string($rawRoute) ? trim($rawRoute) : null;
        if ($contextRoute === '') {
            $contextRoute = null;
        }

        $rawPage = $request->query('page_context');
        $contextPage = is_string($rawPage) ? trim($rawPage) : null;
        if ($contextPage === '') {
            $contextPage = null;
        }

        $payload = $helpActionService->forRequest(
            $query,
            $permissions,
            $brand,
            $contextRoute,
            $contextPage,
            $visibilityContext,
        );

        return response()->json($payload);
    }

    public function ask(Request $request, AuthPermissionService $authPermissionService): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        [$tenant, $brand] = $this->resolveWorkspaceFromAppOrSession($request);

        if (! $tenant instanceof Tenant) {
            return response()->json([
                'kind' => 'workspace_required',
                'message' => 'Choose a company and brand from the workspace menu, then try Ask AI again. You can still search help topics above.',
                'matched_keys' => [],
                'best_score' => 0,
                'suggested' => [],
                'usage' => null,
                'help_ai_question_id' => null,
            ]);
        }

        $permissions = $authPermissionService->effectivePermissions($user, $tenant, $brand);

        $payload = app(HelpAiAskService::class)->ask(
            $validated['question'],
            $permissions,
            $brand,
            $tenant,
            $user
        );

        return response()->json($payload);
    }

    /**
     * User feedback on a specific help AI row (same user + tenant only).
     */
    public function feedback(Request $request, HelpAiQuestion $helpAiQuestion): JsonResponse
    {
        $validated = $request->validate([
            'feedback_rating' => ['required', 'string', 'in:helpful,not_helpful'],
            'feedback_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        if (! $user || (int) $helpAiQuestion->user_id !== (int) $user->id) {
            abort(403);
        }

        /** @var Tenant $tenant */
        $tenant = app('tenant');
        if ((int) $helpAiQuestion->tenant_id !== (int) $tenant->id) {
            abort(403);
        }

        $helpAiQuestion->update([
            'feedback_rating' => $validated['feedback_rating'],
            'feedback_note' => isset($validated['feedback_note']) ? trim((string) $validated['feedback_note']) : null,
            'feedback_submitted_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Help routes intentionally skip ResolveTenant; align permissions + visibility with session when needed.
     *
     * @return array{0: Tenant|null, 1: Brand|null}
     */
    private function resolveWorkspaceFromAppOrSession(Request $request): array
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        $brand = app()->bound('brand') ? app('brand') : null;

        if (! $tenant instanceof Tenant) {
            $tid = $request->session()->get('tenant_id');
            if (is_numeric($tid)) {
                $tenant = Tenant::query()->find((int) $tid);
            }
        }

        if ($tenant instanceof Tenant && ! $brand instanceof Brand) {
            $bid = $request->session()->get('brand_id');
            if (is_numeric($bid)) {
                $brand = Brand::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('id', (int) $bid)
                    ->first();
            }
        }

        return [$tenant instanceof Tenant ? $tenant : null, $brand instanceof Brand ? $brand : null];
    }
}
