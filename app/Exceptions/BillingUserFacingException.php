<?php

namespace App\Exceptions;

use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * Billing errors safe to show in Inertia/HTTP responses (no Stripe IDs or raw gateway text).
 */
class BillingUserFacingException extends \RuntimeException
{
    public string $errorCode;

    /** @var array<string, mixed> */
    public array $logContext = [];

    /** @param  array<string, mixed>  $logContext */
    public function __construct(
        string $message,
        string $errorCode,
        array $logContext = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->logContext = $logContext;
    }

    public static function checkoutFailed(?Throwable $previous = null, array $logContext = []): self
    {
        return new self(
            'We couldn’t start checkout. Please try again, or contact support if the issue continues.',
            'BILLING_CHECKOUT_FAILED',
            $logContext,
            $previous
        );
    }

    public static function subscriptionChangeFailed(?Throwable $previous = null, array $logContext = []): self
    {
        return new self(
            'We couldn’t update your subscription. Please try again, or contact support if the issue continues.',
            'BILLING_SUBSCRIPTION_UPDATE_FAILED',
            $logContext,
            $previous
        );
    }

    public static function stripeNotConfigured(): self
    {
        return new self(
            'Billing is temporarily unavailable. Please try again later.',
            'BILLING_STRIPE_NOT_CONFIGURED',
        );
    }

    /**
     * Local DB claims an active subscription but Stripe reports the customer missing — needs ops, not silent repair.
     */
    public static function stripeCustomerMismatchForActiveSubscription(array $logContext = []): self
    {
        return new self(
            'We couldn’t verify billing for this company. Please contact support.',
            'BILLING_STRIPE_CUSTOMER_MISMATCH',
            $logContext,
        );
    }

    public static function fromStripeApi(ApiErrorException $e, string $errorCode, array $logContext = []): self
    {
        return match ($errorCode) {
            'BILLING_CHECKOUT_FAILED' => self::checkoutFailed($e, $logContext),
            'BILLING_SUBSCRIPTION_UPDATE_FAILED' => self::subscriptionChangeFailed($e, $logContext),
            'BILLING_PAYMENT_METHOD_FAILED' => new self(
                'We couldn’t update your payment method. Please try again or contact support.',
                'BILLING_PAYMENT_METHOD_FAILED',
                $logContext,
                $e
            ),
            'BILLING_PORTAL_FAILED' => new self(
                'We couldn’t open the billing portal. Please try again or contact support.',
                'BILLING_PORTAL_FAILED',
                $logContext,
                $e
            ),
            default => new self(
                'Something went wrong with billing. Please try again or contact support.',
                $errorCode,
                $logContext,
                $e
            ),
        };
    }
}
