<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemIncident;
use App\Services\Reliability\ReliabilityEngine;
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

    public function attemptRepair(SystemIncident $incident, ReliabilityEngine $reliabilityEngine): JsonResponse
    {
        $this->authorizeAdmin();

        $result = $reliabilityEngine->attemptRecovery($incident);

        return response()->json([
            'resolved' => $result['resolved'],
            'changes' => $result['changes'] ?? [],
        ]);
    }

    public function createTicket(SystemIncident $incident, SystemIncidentRecoveryService $recoveryService): JsonResponse
    {
        $this->authorizeAdmin();

        try {
            $ticket = $recoveryService->createTicket($incident);

            return response()->json([
                'created' => $ticket !== null,
                'ticket_id' => $ticket?->id,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[IncidentActionsController] createTicket failed', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'created' => false,
                'ticket_id' => null,
                'error' => $e->getMessage(),
            ], 200);
        }
    }

    public function resolve(SystemIncident $incident, SystemIncidentRecoveryService $recoveryService): JsonResponse
    {
        $this->authorizeAdmin();

        $recoveryService->resolve($incident, false);

        return response()->json(['resolved' => true]);
    }

    /**
     * Bulk actions on multiple incidents.
     * POST /admin/incidents/bulk-actions
     * Body: { action: 'attempt-repair'|'create-ticket'|'resolve', incident_ids: [1,2,...] }
     */
    public function bulkActions(Request $request, ReliabilityEngine $reliabilityEngine, SystemIncidentRecoveryService $recoveryService): JsonResponse
    {
        $this->authorizeAdmin();

        $action = $request->input('action');
        $incidentIds = $request->input('incident_ids', []);

        $validActions = ['attempt-repair', 'create-ticket', 'resolve'];
        if (!in_array($action, $validActions, true)) {
            return response()->json(['error' => 'Invalid action'], 400);
        }

        if (!is_array($incidentIds) || empty($incidentIds)) {
            return response()->json(['error' => 'incident_ids required and must be non-empty'], 400);
        }

        $incidents = SystemIncident::whereIn('id', $incidentIds)->get();
        $results = [];

        foreach ($incidents as $incident) {
            try {
                if ($action === 'attempt-repair') {
                    $result = $reliabilityEngine->attemptRecovery($incident);
                    $results[] = ['id' => $incident->id, 'ok' => true, 'resolved' => $result['resolved'] ?? false];
                } elseif ($action === 'create-ticket') {
                    $ticket = $recoveryService->createTicket($incident);
                    $results[] = ['id' => $incident->id, 'ok' => $ticket !== null, 'ticket_id' => $ticket?->id];
                } else {
                    $reliabilityEngine->resolve($incident, false);
                    $results[] = ['id' => $incident->id, 'ok' => true];
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('[IncidentActionsController] bulk action failed', [
                    'incident_id' => $incident->id,
                    'action' => $action,
                    'error' => $e->getMessage(),
                ]);
                $results[] = ['id' => $incident->id, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        $ticketIds = array_filter(array_column($results, 'ticket_id'));
        $failed = array_filter($results, fn ($r) => !($r['ok'] ?? false));

        return response()->json([
            'results' => $results,
            'ticket_ids' => array_values($ticketIds),
            'success_count' => count($results) - count($failed),
            'failed_count' => count($failed),
        ]);
    }
}
