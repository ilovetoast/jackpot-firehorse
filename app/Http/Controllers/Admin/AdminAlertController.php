<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AlertCandidate;
use App\Services\AlertCandidateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 🔒 Phase 5B Step 2 — Admin Alert Actions Controller
 * 
 * Provides minimal, safe alert lifecycle management for internal admins.
 * Phase 5B Step 1 (UI) is LOCKED - this adds mutation endpoints only.
 * Phase 5A (Tickets) is LOCKED - no ticket modifications.
 * 
 * AdminAlertController
 * 
 * Handles alert acknowledgment and resolution actions.
 * All actions require ai.dashboard.view permission.
 */
class AdminAlertController extends Controller
{
    public function __construct(
        protected AlertCandidateService $alertService
    ) {
        // All methods require AI dashboard view permission
        $this->middleware(function ($request, $next) {
            if (!Auth::user()->can('ai.dashboard.view')) {
                abort(403);
            }
            return $next($request);
        });
    }

    /**
     * Acknowledge an alert candidate.
     * 
     * Transition: open → acknowledged
     * 
     * POST /app/admin/alerts/{alert}/acknowledge
     * 
     * @param Request $request
     * @param AlertCandidate $alert
     * @return \Illuminate\Http\RedirectResponse
     */
    public function acknowledge(Request $request, AlertCandidate $alert)
    {
        // Validate alert is in a state that can be acknowledged
        if ($alert->status !== 'open') {
            return redirect()->back()->with('error', 'Only open alerts can be acknowledged.');
        }

        try {
            $this->alertService->acknowledgeAlert($alert->id);

            return redirect()->back()->with('success', 'Alert acknowledged successfully.');
        } catch (\Throwable $e) {
            \Log::error('[AdminAlertController] Error acknowledging alert', [
                'alert_id' => $alert->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Failed to acknowledge alert: ' . $e->getMessage());
        }
    }

    /**
     * Resolve an alert candidate.
     * 
     * Transition: open / acknowledged → resolved
     * 
     * POST /app/admin/alerts/{alert}/resolve
     * 
     * @param Request $request
     * @param AlertCandidate $alert
     * @return \Illuminate\Http\RedirectResponse
     */
    public function resolve(Request $request, AlertCandidate $alert)
    {
        // Validate alert is in a state that can be resolved
        if (!in_array($alert->status, ['open', 'acknowledged'])) {
            return redirect()->back()->with('error', 'Only open or acknowledged alerts can be resolved.');
        }

        try {
            $this->alertService->resolveAlert($alert->id);

            return redirect()->back()->with('success', 'Alert resolved successfully.');
        } catch (\Throwable $e) {
            \Log::error('[AdminAlertController] Error resolving alert', [
                'alert_id' => $alert->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Failed to resolve alert: ' . $e->getMessage());
        }
    }
}
