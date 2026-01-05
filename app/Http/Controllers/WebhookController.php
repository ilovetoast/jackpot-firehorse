<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierController;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends CashierController
{
    /**
     * Handle customer subscription created.
     */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        $stripeCustomerId = $payload['data']['object']['customer'];
        $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();

        if ($tenant) {
            // Subscription is automatically synced by Cashier
            // Additional logic can be added here if needed
        }

        return $this->successMethod();
    }

    /**
     * Handle customer subscription updated.
     */
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        $stripeCustomerId = $payload['data']['object']['customer'];
        $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();

        if ($tenant) {
            // Subscription is automatically synced by Cashier
            // Additional logic can be added here if needed
        }

        return $this->successMethod();
    }

    /**
     * Handle customer subscription deleted.
     */
    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $stripeCustomerId = $payload['data']['object']['customer'];
        $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();

        if ($tenant) {
            // Subscription is automatically synced by Cashier
            // Additional logic can be added here if needed
        }

        return $this->successMethod();
    }

    /**
     * Handle invoice payment succeeded.
     */
    protected function handleInvoicePaymentSucceeded(array $payload): Response
    {
        $stripeCustomerId = $payload['data']['object']['customer'];
        $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();

        if ($tenant) {
            // Invoice is automatically synced by Cashier
            // Additional logic can be added here if needed
        }

        return $this->successMethod();
    }

    /**
     * Handle invoice payment failed.
     */
    protected function handleInvoicePaymentFailed(array $payload): Response
    {
        $stripeCustomerId = $payload['data']['object']['customer'];
        $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();

        if ($tenant) {
            // Invoice payment failure is automatically handled by Cashier
            // Additional logic can be added here if needed (e.g., send notification)
        }

        return $this->successMethod();
    }
}
