<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminIncubationController extends Controller
{
    /**
     * Extend incubation deadline for an incubated client. Max additional days per action is capped by the
     * incubating agency’s tier (Silver / Gold / Platinum).
     */
    public function extendDeadline(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorizeSiteStaff();

        if (! $tenant->incubated_by_agency_id) {
            return response()->json(['message' => 'This company is not an incubated client.'], 422);
        }

        if ($tenant->hasCompletedOwnershipTransfer()) {
            return response()->json(['message' => 'Ownership transfer is already complete; incubation no longer applies.'], 422);
        }

        $validated = $request->validate([
            'extend_days' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $agency = Tenant::find($tenant->incubated_by_agency_id);
        if (! $agency) {
            return response()->json(['message' => 'Incubating agency not found.'], 422);
        }

        $tier = $agency->agencyTier;
        $maxDays = $tier?->max_support_extension_days;

        if ($maxDays === null || $maxDays < 1) {
            $maxDays = match ($tier?->name) {
                'Silver' => 14,
                'Gold' => 30,
                'Platinum' => 180,
                default => 14,
            };
        }

        if ($validated['extend_days'] > $maxDays) {
            return response()->json([
                'message' => "Extension cannot exceed {$maxDays} days for this agency tier ({$tier?->name}).",
                'max_extend_days' => $maxDays,
            ], 422);
        }

        $newExpires = ($tenant->incubation_expires_at ?? now())->copy()->addDays($validated['extend_days']);

        DB::transaction(function () use ($tenant, $newExpires, $validated) {
            $tenant->incubation_expires_at = $newExpires;
            $tenant->incubation_locked_at = null;
            $tenant->save();

            Log::info('[Incubation] Admin extended deadline', [
                'tenant_id' => $tenant->id,
                'extend_days' => $validated['extend_days'],
                'new_incubation_expires_at' => $newExpires->toIso8601String(),
                'actor_id' => Auth::id(),
                'reason' => $validated['reason'] ?? null,
            ]);
        });

        $tenant->refresh();

        return response()->json([
            'message' => 'Incubation deadline extended.',
            'incubation_expires_at' => $tenant->incubation_expires_at?->toIso8601String(),
            'max_extend_days_for_tier' => $maxDays,
        ]);
    }

    protected function authorizeSiteStaff(): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(403);
        }
        if ((int) $user->id === 1) {
            return;
        }
        $roles = $user->getSiteRoles();
        $allowed = array_intersect($roles, ['site_owner', 'site_admin', 'site_support']);
        if ($allowed === []) {
            abort(403, 'Only site staff can extend incubation deadlines.');
        }
    }
}
