<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Carbon;

class PlanLimitExceededException extends Exception
{
    public const LIMIT_AI_CREDITS = 'ai_credits';

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
     * User-safe copy for APIs (no need to show raw internal exception string for credit limits).
     */
    public function getUserFacingMessage(): string
    {
        if ($this->limitType === self::LIMIT_AI_CREDITS) {
            $label = $this->nextCreditResetDate()->format('F j, Y');

            return "This workspace has used all of its monthly AI credits for this period ({$this->currentCount} of {$this->maxAllowed} used). "
                ."Credits reset on {$label}. You can add AI credits if your plan includes add-ons, or try again after that date.";
        }

        return $this->getMessage();
    }

    /**
     * First day of the next calendar month (app timezone) — when monthly AI credits reset.
     */
    public function nextCreditResetDate(): Carbon
    {
        return Carbon::now()->timezone((string) config('app.timezone', 'UTC'))->startOfMonth()->addMonth();
    }

    /**
     * @return array{message: string, limit_type: string, current_count: int, max_allowed: int, credits_reset_on?: string, credits_reset_label?: string}
     */
    public function toApiArray(): array
    {
        $base = [
            'message' => $this->getUserFacingMessage(),
            'limit_type' => $this->limitType,
            'current_count' => $this->currentCount,
            'max_allowed' => $this->maxAllowed,
        ];

        if ($this->limitType === self::LIMIT_AI_CREDITS) {
            $reset = $this->nextCreditResetDate();
            $base['credits_reset_on'] = $reset->toDateString();
            $base['credits_reset_label'] = $reset->format('F j, Y');
        }

        return $base;
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json($this->toApiArray(), 403);
        }

        return redirect()->back()->withErrors([
            'plan_limit' => $this->getUserFacingMessage(),
        ]);
    }
}
