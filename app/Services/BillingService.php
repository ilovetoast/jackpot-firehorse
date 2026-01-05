<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Collection;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Stripe\Exception\ApiErrorException;

class BillingService
{
    /**
     * Create a Stripe checkout session for subscription.
     */
    public function createCheckoutSession(Tenant $tenant, string $priceId): string
    {
        try {
            $checkout = $tenant->newSubscription('default', $priceId)
                ->checkout([
                    'success_url' => route('billing.success'),
                    'cancel_url' => route('billing'),
                ]);

            return $checkout->url;
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to create checkout session: ' . $e->getMessage());
        }
    }

    /**
     * Update tenant subscription to a new plan.
     */
    public function updateSubscription(Tenant $tenant, string $priceId): void
    {
        try {
            if (! $tenant->subscribed()) {
                // Create new subscription
                $tenant->newSubscription('default', $priceId)->create();
            } else {
                // Update existing subscription
                $tenant->subscription('default')->swap($priceId);
            }
        } catch (IncompletePayment $e) {
            throw new \RuntimeException('Payment incomplete: ' . $e->getMessage());
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to update subscription: ' . $e->getMessage());
        }
    }

    /**
     * Cancel tenant subscription.
     */
    public function cancelSubscription(Tenant $tenant): void
    {
        try {
            if ($tenant->subscribed()) {
                $tenant->subscription('default')->cancel();
            }
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to cancel subscription: ' . $e->getMessage());
        }
    }

    /**
     * Resume a cancelled subscription.
     */
    public function resumeSubscription(Tenant $tenant): void
    {
        try {
            if ($tenant->subscription('default')->canceled()) {
                $tenant->subscription('default')->resume();
            }
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to resume subscription: ' . $e->getMessage());
        }
    }

    /**
     * Get invoices for tenant.
     */
    public function getInvoices(Tenant $tenant): Collection
    {
        try {
            if (! $tenant->stripe_id) {
                return collect([]);
            }

            $invoices = $tenant->invoices();

            return collect($invoices)->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'amount' => $invoice->amount_paid / 100, // Convert cents to dollars
                    'currency' => strtoupper($invoice->currency),
                    'status' => $invoice->status,
                    'date' => $invoice->created,
                    'url' => $invoice->hosted_invoice_url,
                    'pdf' => $invoice->invoice_pdf,
                ];
            });
        } catch (ApiErrorException $e) {
            return collect([]);
        }
    }

    /**
     * Get current plan name from subscription.
     */
    public function getCurrentPlan(Tenant $tenant): ?string
    {
        $planService = new PlanService();

        return $planService->getCurrentPlan($tenant);
    }

    /**
     * Update payment method.
     */
    public function updatePaymentMethod(Tenant $tenant, string $paymentMethodId): void
    {
        try {
            $tenant->updateDefaultPaymentMethod($paymentMethodId);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to update payment method: ' . $e->getMessage());
        }
    }
}
