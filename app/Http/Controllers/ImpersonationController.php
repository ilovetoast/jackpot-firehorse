<?php

namespace App\Http\Controllers;

use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    /**
     * Legacy POST /app/impersonation/start (Team / in-app) is intentionally disabled.
     *
     * Internal support sessions must be started from Command Center → Support access → Start support session
     * ({@see \App\Http\Controllers\Admin\ImpersonationAdminController::start}) so tenant context, mode, reason,
     * and ticket reference are validated and audited consistently. A bare 403 here is expected — not a routing bug.
     */
    public function start(): RedirectResponse
    {
        abort(403, 'Support sessions must be started from Command Center → Support access → Start support session.');
    }

    public function stop(Request $request, ImpersonationService $impersonation): RedirectResponse
    {
        $impersonation->endSession($request);

        return redirect()->route('app')->with('success', 'Support session ended.');
    }
}
