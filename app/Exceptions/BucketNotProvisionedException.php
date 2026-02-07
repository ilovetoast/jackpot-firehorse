<?php

namespace App\Exceptions;

use Exception;

class BucketNotProvisionedException extends Exception
{
    /**
     * Create a new exception instance.
     */
    public function __construct(
        public int $tenantId,
        ?string $message = null
    ) {
        $message = $message ?? 'No active storage bucket for this tenant. Run on a worker or CLI: php artisan tenants:ensure-buckets';

        parent::__construct($message);
    }

    /**
     * Render the exception as an HTTP response (e.g. 503 Service Unavailable).
     */
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'tenant_id' => $this->tenantId,
            ], 503);
        }

        return redirect()->back()->withErrors([
            'storage' => $this->getMessage(),
        ])->setStatusCode(503);
    }
}
