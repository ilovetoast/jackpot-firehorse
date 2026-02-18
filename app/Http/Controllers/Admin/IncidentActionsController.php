<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemIncident;
use App\Services\SystemIncidentRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin incident action endpoints.
 *
 * POST /admin/incidents/{incident}/attempt-repair
 * POST /admin/incidents/{incident}/create-ticket
 * POST /admin/incidents/{incident}/resolve
 */
class IncidentActionsController extends Controller
{
    protected function authorizeAdmin(): void
    {
        $user = Auth::user();
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        if (!$isSiteOwner && !$isSiteAdmin) {
            abort(403, 'Only system administrators can access this page.');
        }
    }

    public function attemptRepair(SystemIncident $incident, SystemIncidentRecoveryService $recoveryService): JsonResponse
    {
        $this->authorizeAdmin();

        $result = $recoveryService->attemptRepair($incident);

        return response()->json([
            'resolved' => $result['resolved'],
            'changes' => $result['changes'] ?? [],
        ]);
    }

    public function createTicket(SystemIncident $incident, SystemIncidentRecoveryService $recoveryService): JsonResponse
    {
        $this->authorizeAdmin();

        $ticket = $recoveryService->createTicket($incident);

        return response()->json([
            'created' => $ticket !== null,
            'ticket_id' => $ticket?->id,
        ]);
    }

    public function resolve(SystemIncident $incident, SystemIncidentRecoveryService $recoveryService): JsonResponse
    {
        $this->authorizeAdmin();

        $recoveryService->resolve($incident, false);

        return response()->json(['resolved' => true]);
    }
}
