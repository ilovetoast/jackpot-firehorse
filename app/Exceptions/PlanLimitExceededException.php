<?php

namespace App\Exceptions;

use Exception;

class PlanLimitExceededException extends Exception
{
    /**
     * Create a new exception instance.
     */
    public function __construct(
        public string $limitType,
        public int $currentCount,
        public int $maxAllowed,
        ?string $message = null
    ) {
        $message = $message ?? "Plan limit exceeded for {$limitType}. Current: {$currentCount}, Maximum: {$maxAllowed}";

        parent::__construct($message);
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'limit_type' => $this->limitType,
                'current_count' => $this->currentCount,
                'max_allowed' => $this->maxAllowed,
            ], 403);
        }

        return redirect()->back()->withErrors([
            'plan_limit' => $this->getMessage(),
        ]);
    }
}
