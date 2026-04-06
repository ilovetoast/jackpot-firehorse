<?php

namespace App\Http\Controllers\Prostaff;

use App\Exceptions\CreatorModuleInactiveException;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\User;
use App\Services\Prostaff\AssignProstaffMember;
use App\Services\Prostaff\ResolveCreatorsDashboardAccess;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Assign or update prostaff membership (Creator module required).
 */
class ProstaffMembershipController extends Controller
{
    /**
     * POST /app/api/brands/{brand}/prostaff/members
     */
    public function store(Request $request, Brand $brand): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $tenant = app('tenant');

        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Brand does not belong to this tenant.'], 403);
        }

        $this->authorize('view', $brand);

        if (! app(ResolveCreatorsDashboardAccess::class)->canManage($user, $tenant, $brand)) {
            return response()->json(['error' => 'You do not have permission to manage prostaff assignments.'], 403);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'target_uploads' => ['nullable', 'integer', 'min:0'],
            'period_type' => ['nullable', 'string', 'max:32'],
            'period_start' => ['nullable', 'date'],
        ]);

        $subject = User::query()->findOrFail($validated['user_id']);

        if (! $subject->belongsToTenant($tenant->id)) {
            throw ValidationException::withMessages([
                'user_id' => ['User must belong to this workspace before prostaff assignment.'],
            ]);
        }

        $payload = array_filter([
            'target_uploads' => $validated['target_uploads'] ?? null,
            'period_type' => $validated['period_type'] ?? null,
            'period_start' => $validated['period_start'] ?? null,
            'assigned_by_user_id' => $user->id,
        ], static fn ($v) => $v !== null);

        try {
            $membership = app(AssignProstaffMember::class)->assign($subject, $brand, $payload);
        } catch (CreatorModuleInactiveException $e) {
            return response()->json($e->clientPayload(), 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $membership->id,
            'user_id' => $membership->user_id,
            'brand_id' => $membership->brand_id,
            'status' => $membership->status,
        ], 201);
    }

}
