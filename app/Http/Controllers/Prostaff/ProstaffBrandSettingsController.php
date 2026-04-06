<?php

namespace App\Http\Controllers\Prostaff;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ProstaffBrandSettingsController extends Controller
{
    /**
     * PUT /app/api/brands/{brand}/prostaff/approvers
     *
     * @return JsonResponse{ok: true, user_ids: list<int>}
     */
    public function updateApprovers(Request $request, Brand $brand): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $tenant = app('tenant');

        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this workspace.');
        }

        $this->authorize('update', $brand);

        if (! $user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to update brand creator settings.');
        }

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $uniqueIds = array_values(array_unique(array_map('intval', $validated['user_ids'])));

        foreach ($uniqueIds as $uid) {
            if (! $brand->users()->where('users.id', $uid)->wherePivotNull('removed_at')->exists()) {
                throw ValidationException::withMessages([
                    'user_ids' => ["User #{$uid} must be an active member of this brand."],
                ]);
            }
        }

        $settings = $brand->settings ?? [];
        $settings['creator_module_approver_user_ids'] = $uniqueIds;
        $brand->update(['settings' => $settings]);

        return response()->json([
            'ok' => true,
            'user_ids' => $uniqueIds,
        ]);
    }
}
