<?php

namespace App\Http\Middleware;

use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ImpersonationMiddleware
{
    public function __construct(
        protected ImpersonationService $impersonation
    ) {}

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->impersonation->resetPerRequestResolution();

        if ($redirect = $this->impersonation->enforceExpiration($request)) {
            return $redirect;
        }

        $this->impersonation->applyAuthSwap($request);

        $response = $next($request);

        if ($this->impersonation->isActive()) {
            $this->impersonation->logRequestIfActive($request);
        }

        return $response;
    }
}
