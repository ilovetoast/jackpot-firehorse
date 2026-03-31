<?php

namespace App\Http\Controllers;

use App\Models\TenantAgency;
use App\Services\TenantAgencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Agency-side actions for tenant_agencies rows (client grants access to this agency).
 */
class AgencyTenantAgencySyncController extends Controller
{
    public function __construct(
        protected TenantAgencyService $tenantAgencyService
    ) {}

    /**
     * POST /app/api/agency/tenant-agencies/{tenantAgency}/sync-users
     *
     * Adds client memberships for agency staff who are not on the client yet (same as link-time provisioning).
     */
    public function sync(TenantAgency $tenantAgency): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();

        if (! $tenant || ! $user) {
            abort(403);
        }

        if (! $tenant->is_agency) {
            abort(403, 'Only available from an agency workspace.');
        }

        if ((int) $tenantAgency->agency_tenant_id !== (int) $tenant->id) {
            abort(404);
        }

        if (! $user->canForContext('team.manage', $tenant, null)) {
            return response()->json(['error' => 'You do not have permission to sync agency users.'], 403);
        }

        $result = $this->tenantAgencyService->syncUsersForLink($tenantAgency);

        return response()->json([
            'success' => true,
            'added' => $result['added'],
            'skipped_existing_membership' => $result['skipped_existing_membership'],
        ]);
    }
}
