<?php

namespace App\Services\Billing;

use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;

/**
 * Verifies that a Stripe customer ID exists for the Stripe account tied to STRIPE_SECRET.
 *
 * Customer IDs are not portable across Stripe accounts or live/test mode. A copied DB against
 * a different STRIPE_SECRET will produce "No such customer" until the local stripe_id is cleared.
 */
final class StripeCustomerVerifier
{
    /**
     * @throws ApiErrorException When Stripe returns an error other than missing customer (e.g. rate limit, auth).
     */
    public function customerExistsInStripeAccount(string $customerId): bool
    {
        $secret = config('services.stripe.secret');
        if ($secret === null || $secret === '') {
            throw new \RuntimeException('Stripe is not configured.');
        }

        Stripe::setApiKey($secret);

        try {
            Customer::retrieve($customerId);

            return true;
        } catch (ApiErrorException $e) {
            if ($this->isNoSuchCustomer($e)) {
                return false;
            }

            throw $e;
        }
    }

    private function isNoSuchCustomer(ApiErrorException $e): bool
    {
        return $e->getStripeCode() === 'resource_missing'
            || str_contains($e->getMessage(), 'No such customer');
    }
}
