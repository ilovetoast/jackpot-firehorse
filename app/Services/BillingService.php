<?php

namespace App\Services;

use App\Enums\EventType;
use App\Models\Tenant;
use App\Services\ActivityRecorder;
use App\Services\PlanService;
use Illuminate\Support\Collection;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Stripe;

class BillingService
{
    /**
     * Create a Stripe checkout session for subscription.
     * 
     * IMPORTANT: If tenant already has an active subscription, this will create a NEW subscription.
     * For upgrades/downgrades, use updateSubscription() instead, which handles proration automatically.
     * 
     * @param Tenant $tenant
     * @param string $priceId Stripe price ID
     * @param bool $allowMultipleSubscriptions If false, will throw exception if subscription exists
     * @return string Checkout session URL
     * @throws \RuntimeException If subscription exists and allowMultipleSubscriptions is false
     */
    public function createCheckoutSession(Tenant $tenant, string $priceId, bool $allowMultipleSubscriptions = false): string
    {
        try {
            // Check if tenant already has an active subscription
            $existingSubscription = $tenant->subscription('default');
            
            if ($existingSubscription && !$allowMultipleSubscriptions) {
                // If they have an active subscription, they should use updateSubscription instead
                // This prevents duplicate subscriptions and ensures proper proration
                throw new \RuntimeException(
                    'You already have an active subscription. Please use the upgrade/downgrade option instead of creating a new subscription.'
                );
            }
            
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
     * 
     * @param Tenant $tenant
     * @param string $priceId New Stripe price ID
     * @param string|null $oldPlanId Old plan ID (for activity logging)
     * @param string|null $newPlanId New plan ID (for activity logging)
     * @return array ['action' => 'upgrade'|'downgrade'|'created', 'old_plan' => string, 'new_plan' => string]
     */
    public function updateSubscription(Tenant $tenant, string $priceId, ?string $oldPlanId = null, ?string $newPlanId = null): array
    {
        try {
            $oldSubscription = $tenant->subscription('default');
            $wasSubscribed = $oldSubscription !== null;
            
            // Check if subscription has incomplete payment
            if ($wasSubscribed && $oldSubscription->hasIncompletePayment()) {
                // Get the latest payment for redirect URL
                $latestPayment = $oldSubscription->latestPayment();
                throw new \RuntimeException(
                    'Your subscription has an incomplete payment. Please complete your payment before changing plans. ' .
                    ($latestPayment ? 'Payment ID: ' . $latestPayment->id : '')
                );
            }
            
            // Get old plan info
            $oldPlan = $oldPlanId ?? (new PlanService())->getCurrentPlan($tenant);
            
            // Get price info to determine new plan
            if (!$newPlanId) {
                $stripeSecret = env('STRIPE_SECRET');
                if ($stripeSecret) {
                    Stripe::setApiKey($stripeSecret);
                    try {
                        $price = Price::retrieve($priceId);
                        // Try to match price ID to plan config
                        $plans = config('plans');
                        foreach ($plans as $planKey => $planConfig) {
                            if ($planConfig['stripe_price_id'] === $priceId) {
                                $newPlanId = $planKey;
                                break;
                            }
                        }
                    } catch (\Exception $e) {
                        // If we can't retrieve price, use 'unknown'
                        $newPlanId = 'unknown';
                    }
                }
            }
            
            if (! $wasSubscribed) {
                // Create new subscription
                $tenant->newSubscription('default', $priceId)->create();
                $action = 'created';
            } else {
                // Check if subscription has incomplete payment using Cashier method
                if (method_exists($oldSubscription, 'hasIncompletePayment') && $oldSubscription->hasIncompletePayment()) {
                    throw new \RuntimeException(
                        'Your subscription has an incomplete payment. Please complete your payment before changing plans.'
                    );
                }
                
                // Also check subscription status from Stripe before updating
                $stripeSecret = env('STRIPE_SECRET');
                if ($stripeSecret && $oldSubscription->stripe_id) {
                    Stripe::setApiKey($stripeSecret);
                    try {
                        $stripeSubscription = \Stripe\Subscription::retrieve($oldSubscription->stripe_id);
                        // If status is incomplete, past_due, or unpaid, we can't update
                        if (in_array($stripeSubscription->status, ['incomplete', 'incomplete_expired', 'past_due', 'unpaid'])) {
                            throw new \RuntimeException(
                                'Your subscription payment is incomplete. Please update your payment method and complete the payment before changing plans.'
                            );
                        }
                    } catch (\Stripe\Exception\ApiErrorException $e) {
                        // If we can't fetch from Stripe, proceed but might fail
                        // The swap() call below will throw an error if payment is incomplete
                    }
                }
                
                // Update existing subscription
                // Laravel Cashier's swap() method automatically handles proration:
                // - Upgrades: Customer is charged immediately for the prorated difference
                // - Downgrades: Customer receives a credit that applies to their next invoice
                // Stripe calculates proration based on:
                //   - Time remaining in current billing period
                //   - Price difference between old and new plans
                //   - Proration is always enabled by default (can be disabled with noProrate())
                $tenant->subscription('default')->swap($priceId);
                
                // Determine if upgrade or downgrade by comparing plan prices
                $oldPrice = $this->getPlanPrice($oldPlan);
                $newPrice = $this->getPlanPrice($newPlanId ?? 'unknown');
                
                if ($newPrice > $oldPrice) {
                    $action = 'upgrade';
                } elseif ($newPrice < $oldPrice) {
                    $action = 'downgrade';
                } else {
                    $action = 'updated';
                }
            }
            
            // Log activity
            ActivityRecorder::record(
                tenant: $tenant,
                eventType: EventType::SUBSCRIPTION_UPDATED,
                actor: auth()->user(),
                metadata: [
                    'action' => $action,
                    'old_plan' => $oldPlan,
                    'new_plan' => $newPlanId ?? 'unknown',
                    'price_id' => $priceId,
                ]
            );
            
            return [
                'action' => $action,
                'old_plan' => $oldPlan,
                'new_plan' => $newPlanId ?? 'unknown',
            ];
        } catch (IncompletePayment $e) {
            throw new \RuntimeException('Payment incomplete: ' . $e->getMessage());
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to update subscription: ' . $e->getMessage());
        }
    }
    
    /**
     * Get plan price for comparison.
     */
    private function getPlanPrice(string $planId): float
    {
        $plan = config("plans.{$planId}");
        if (!$plan) {
            return 0;
        }
        
        $priceId = $plan['stripe_price_id'] ?? null;
        if (!$priceId || $priceId === 'price_free') {
            return 0;
        }
        
        try {
            $stripeSecret = env('STRIPE_SECRET');
            if ($stripeSecret) {
                Stripe::setApiKey($stripeSecret);
                $price = Price::retrieve($priceId);
                return ($price->unit_amount ?? 0) / 100; // Convert cents to dollars
            }
        } catch (\Exception $e) {
            // If we can't get price, return 0
        }
        
        return 0;
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

    /**
     * Get Stripe Customer Portal URL for self-service billing management.
     */
    public function getCustomerPortalUrl(Tenant $tenant, string $returnUrl): string
    {
        try {
            if (! $tenant->stripe_id) {
                throw new \RuntimeException('Tenant does not have a Stripe customer ID.');
            }

            return $tenant->billingPortalUrl($returnUrl);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to create customer portal URL: ' . $e->getMessage());
        }
    }

    /**
     * Refund an invoice (full or partial).
     * 
     * @param Tenant $tenant
     * @param string $invoiceId Stripe invoice ID
     * @param int|null $amount Amount in cents (null for full refund)
     * @param string|null $reason Reason for refund
     * @return array Refund details
     */
    public function refundInvoice(Tenant $tenant, string $invoiceId, ?int $amount = null, ?string $reason = null): array
    {
        try {
            if (! $tenant->stripe_id) {
                throw new \RuntimeException('Tenant does not have a Stripe customer ID.');
            }

            $invoice = $tenant->findInvoice($invoiceId);
            
            if (! $invoice || ! $invoice->charge) {
                throw new \RuntimeException('Invoice not found or has no charge.');
            }

            $refundParams = [
                'charge' => $invoice->charge,
            ];

            if ($amount) {
                $refundParams['amount'] = $amount;
            }

            if ($reason) {
                $refundParams['reason'] = $reason;
            }

            $refund = \Stripe\Refund::create($refundParams, [
                'api_key' => config('services.stripe.secret'),
            ]);

            // Log refund for audit trail
            \Log::info('Refund processed', [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'invoice_id' => $invoiceId,
                'refund_id' => $refund->id,
                'amount' => $refund->amount,
                'reason' => $reason,
            ]);

            return [
                'id' => $refund->id,
                'amount' => $refund->amount / 100, // Convert cents to dollars
                'currency' => strtoupper($refund->currency),
                'status' => $refund->status,
                'reason' => $refund->reason,
            ];
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to process refund: ' . $e->getMessage());
        }
    }
}
