<?php

namespace App\Exceptions;

use RuntimeException;

class BucketProvisioningNotAllowedException extends RuntimeException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(
        public string $environment,
        ?string $message = null
    ) {
        $message = $message ?? 'Bucket provisioning is not allowed in a web request. Provisioning may only run via CLI or queued jobs.';

        parent::__construct($message);
    }

    /**
     * Render the exception as an HTTP response (e.g. 503 or 500).
     */
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'environment' => $this->environment,
            ], 503);
        }

        return redirect()->back()->withErrors([
            'storage' => $this->getMessage(),
        ])->setStatusCode(503);
    }
}
