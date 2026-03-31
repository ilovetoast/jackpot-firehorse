<?php

namespace App\Http\Middleware;

use App\Services\IncubationWorkspaceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIncubationWorkspaceNotLocked
{
    public function __construct(
        protected IncubationWorkspaceService $incubationWorkspaceService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        if ($tenant && $this->incubationWorkspaceService->isWorkspaceLocked($tenant)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $this->incubationWorkspaceService->lockReasonMessage(),
                    'incubation_locked' => true,
                ], 403);
            }

            abort(403, $this->incubationWorkspaceService->lockReasonMessage());
        }

        return $next($request);
    }
}
