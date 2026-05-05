<?php

namespace App\Services;

use App\Enums\EventType;
use App\Exceptions\BillingUserFacingException;
use App\Models\Tenant;
use App\Services\ActivityRecorder;
use App\Services\Billing\StripeCustomerVerifier;
use App\Services\PlanService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Stripe;
use Stripe\SubscriptionItem;

/**
 * Tenant-scoped billing (Laravel Cashier Billable on {@see Tenant}).
 *
 * Stripe customer IDs (stripe_id) are tied to the Stripe account and mode of STRIPE_SECRET.
 * Sharing a database dump across environments with different keys yields "No such customer" until repaired.
 */
class BillingService
{
    public function __construct(
        protected StripeCustomerVerifier $stripeCustomerVerifier,
    ) {}

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
    public function createCheckoutSession(
        Tenant $tenant,
        string $priceId,
        bool $allowMultipleSubscriptions = false,
        ?int $actingUserId = null,
        ?string $planKey = null,
    ): string {
        if (empty(config('services.stripe.secret'))) {
            throw BillingUserFacingException::stripeNotConfigured();
        }

        try {
            $existingSubscription = $tenant->subscription('default');

            if ($existingSubscription && ! $allowMultipleSubscriptions) {
                throw new \RuntimeException(
                    'You already have an active subscription. Please use the upgrade/downgrade option instead of creating a new subscription.'
                );
            }

            $diagnosticBase = $this->checkoutDiagnosticContext($tenant, $actingUserId, $planKey, $priceId);
            $ensureResult = $this->ensureValidStripeCustomer($tenant, $actingUserId, $diagnosticBase);

            $this->logCheckoutInitiating(array_merge($diagnosticBase, [
                'stripe_id_after_ensure' => $tenant->stripe_id,
                'recovered_stale_customer' => $ensureResult['recovered_stale'],
                'created_new_customer' => $ensureResult['created_new'],
            ]));

            $metadata = $this->checkoutSessionMetadataPayload($tenant, $actingUserId, $planKey);

            $checkout = $tenant->newSubscription('default', $priceId)
                ->checkout([
                    // Stripe replaces {CHECKOUT_SESSION_ID}; sync on success avoids waiting for webhooks.
                    'success_url' => route('billing.success').'?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => route('billing'),
                    'metadata' => $metadata,
                    'subscription_data' => [
                        'metadata' => $metadata,
                    ],
                ]);

            return $checkout->url;
        } catch (ApiErrorException $e) {
            $this->logStripeApiFailure('billing.checkout.stripe_api_error', $e, $this->checkoutDiagnosticContext($tenant, $actingUserId, $planKey, $priceId));

            throw BillingUserFacingException::fromStripeApi($e, 'BILLING_CHECKOUT_FAILED', $this->checkoutDiagnosticContext($tenant, $actingUserId, $planKey, $priceId));
        }
    }

    /**
     * Ensure tenants.stripe_id exists for the configured Stripe account. Recovers stale IDs only when
     * Stripe reports the customer missing and local state does not claim an active paid subscription.
     *
     * @param  array<string, mixed>  $diagnosticContext
     * @return array{recovered_stale: bool, created_new: bool}
     */
    public function ensureValidStripeCustomer(Tenant $tenant, ?int $actingUserId = null, array $diagnosticContext = []): array
    {
        $result = ['recovered_stale' => false, 'created_new' => false];

        if (empty(config('services.stripe.secret'))) {
            $this->logEnsureCustomer('billing.ensure_customer.skip_no_secret', $tenant, $actingUserId, $diagnosticContext, $result);

            return $result;
        }

        if (! $tenant->stripe_id) {
            $this->createLocalStripeCustomer($tenant);
            $result['created_new'] = true;
            $this->logEnsureCustomer('billing.ensure_customer.created', $tenant, $actingUserId, $diagnosticContext, $result);

            return $result;
        }

        if ($this->stripeCustomerVerifier->customerExistsInStripeAccount($tenant->stripe_id)) {
            $this->logEnsureCustomer('billing.ensure_customer.valid', $tenant, $actingUserId, $diagnosticContext, $result);

            return $result;
        }

        $this->assertMayClearStaleStripeCustomer($tenant, $diagnosticContext);

        $removedId = $tenant->stripe_id;
        Log::warning('billing.stripe_customer_stale_cleared', array_merge($diagnosticContext, [
            'tenant_id' => $tenant->id,
            'removed_stripe_id' => $removedId,
            'acting_user_id' => $actingUserId,
        ]));

        $tenant->stripe_id = null;
        $tenant->pm_type = null;
        $tenant->pm_last_four = null;
        $tenant->saveQuietly();
        $tenant->refresh();

        $this->createLocalStripeCustomer($tenant);
        $result['recovered_stale'] = true;
        $result['created_new'] = true;
        $this->logEnsureCustomer('billing.ensure_customer.recovered', $tenant, $actingUserId, array_merge($diagnosticContext, [
            'removed_stripe_id' => $removedId,
        ]), $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $diagnosticContext
     */
    protected function assertMayClearStaleStripeCustomer(Tenant $tenant, array $diagnosticContext): void
    {
        $sub = $tenant->subscription('default');
        if ($sub && in_array($sub->stripe_status, ['active', 'trialing', 'past_due'], true)) {
            Log::critical('billing.stripe_customer_missing_for_active_subscription', array_merge($diagnosticContext, [
                'tenant_id' => $tenant->id,
                'local_subscription_stripe_status' => $sub->stripe_status,
            ]));

            throw BillingUserFacingException::stripeCustomerMismatchForActiveSubscription([
                'tenant_id' => $tenant->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function createLocalStripeCustomer(Tenant $tenant): void
    {
        if ($tenant->stripe_id) {
            return;
        }

        if (empty(config('services.stripe.secret'))) {
            throw BillingUserFacingException::stripeNotConfigured();
        }

        Stripe::setApiKey((string) config('services.stripe.secret'));

        $payload = $this->stripeCustomerBootstrapPayload($tenant);
        $tenant->createAsStripeCustomer($payload);

        $tenant->refresh();
    }

    /**
     * @return array{name?: string, email?: string, metadata: array<string, string>}
     */
    protected function stripeCustomerBootstrapPayload(Tenant $tenant): array
    {
        $email = $tenant->email;
        if ($email === null || $email === '') {
            $email = $tenant->owner()?->email;
        }

        $meta = [
            'tenant_id' => (string) $tenant->id,
            'tenant_slug' => (string) ($tenant->slug ?? ''),
        ];

        $out = ['metadata' => $meta];
        if (is_string($email) && $email !== '') {
            $out['email'] = $email;
        }
        $name = trim((string) ($tenant->name ?? ''));
        if ($name !== '') {
            $out['name'] = $name;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    protected function checkoutSessionMetadataPayload(Tenant $tenant, ?int $actingUserId, ?string $planKey): array
    {
        return [
            'tenant_id' => (string) $tenant->id,
            'plan_key' => (string) ($planKey ?? ''),
            'user_id' => $actingUserId !== null ? (string) $actingUserId : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkoutDiagnosticContext(Tenant $tenant, ?int $actingUserId, ?string $planKey, string $priceId): array
    {
        return [
            'app_env' => config('app.env'),
            'tenant_id' => $tenant->id,
            'user_id' => $actingUserId,
            'plan_key' => $planKey,
            'price_id' => $priceId,
            'stripe_id_before_ensure' => $tenant->stripe_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $diagnosticContext
     * @param  array{recovered_stale: bool, created_new: bool}  $ensureResult
     */
    protected function logEnsureCustomer(string $message, Tenant $tenant, ?int $actingUserId, array $diagnosticContext, array $ensureResult): void
    {
        Log::info($message, array_merge($diagnosticContext, [
            'tenant_id' => $tenant->id,
            'acting_user_id' => $actingUserId,
            'stripe_id' => $tenant->stripe_id,
            'recovered_stale' => $ensureResult['recovered_stale'],
            'created_new' => $ensureResult['created_new'],
        ]));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function logCheckoutInitiating(array $extra): void
    {
        Log::info('billing.checkout.initiating', $extra);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logStripeApiFailure(string $channel, ApiErrorException $e, array $context): void
    {
        Log::error($channel, array_merge($context, [
            'stripe_code' => $e->getStripeCode(),
            'stripe_message' => $e->getMessage(),
            'exception_class' => $e::class,
        ]));
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
        if (empty(config('services.stripe.secret'))) {
            throw BillingUserFacingException::stripeNotConfigured();
        }

        try {
            $this->ensureValidStripeCustomer($tenant);

            $oldSubscription = $tenant->subscription('default');
            $wasSubscribed = $oldSubscription !== null;
            
            // Check if subscription has incomplete payment
            // Only check if subscription has a Stripe customer (stripe_id) - forced plans may not have one
            if ($wasSubscribed && $oldSubscription->stripe_id && $tenant->stripe_id && $oldSubscription->hasIncompletePayment()) {
                // Set the owner on the subscription so Cashier methods work properly
                // When we query subscriptions directly, the owner isn't automatically set
                if (method_exists($oldSubscription, 'setOwner')) {
                    $oldSubscription->setOwner($tenant);
                } elseif (property_exists($oldSubscription, 'owner')) {
                    $oldSubscription->owner = $tenant;
                } elseif (method_exists($oldSubscription, 'setRelation')) {
                    $oldSubscription->setRelation('owner', $tenant);
                }
                
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
                $stripeSecret = config('services.stripe.secret');
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
                $stripeSecret = config('services.stripe.secret');
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

            $resolvedNew = $newPlanId ?? 'unknown';
            if ($resolvedNew !== 'unknown' && $oldPlan !== $resolvedNew) {
                app(\App\Services\Billing\SubscriptionBillingNotifier::class)->notifyPlanChangedAfterSync(
                    $tenant,
                    $oldPlan,
                    $resolvedNew
                );
            }
            
            return [
                'action' => $action,
                'old_plan' => $oldPlan,
                'new_plan' => $newPlanId ?? 'unknown',
            ];
        } catch (BillingUserFacingException $e) {
            throw $e;
        } catch (IncompletePayment $e) {
            throw new \RuntimeException('Payment incomplete: ' . $e->getMessage());
        } catch (ApiErrorException $e) {
            $ctx = [
                'app_env' => config('app.env'),
                'tenant_id' => $tenant->id,
                'price_id' => $priceId,
            ];
            $this->logStripeApiFailure('billing.subscription_update.stripe_api_error', $e, $ctx);

            throw BillingUserFacingException::fromStripeApi($e, 'BILLING_SUBSCRIPTION_UPDATE_FAILED', $ctx);
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
            $stripeSecret = config('services.stripe.secret');
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
                $subscription = $tenant->subscription('default');
                $planService = new PlanService();
                $currentPlan = $planService->getCurrentPlan($tenant);
                
                $subscription->cancel();

                $subscription->refresh();
                $endsAt = $subscription->ends_at;

                app(\App\Services\Billing\SubscriptionBillingNotifier::class)->notifyCancellationScheduled(
                    $tenant,
                    $currentPlan,
                    $endsAt
                );
                
                // Log activity
                ActivityRecorder::record(
                    tenant: $tenant,
                    eventType: EventType::SUBSCRIPTION_CANCELED,
                    actor: auth()->user(),
                    metadata: [
                        'plan' => $currentPlan,
                        'plan_name' => config("plans.{$currentPlan}.name", ucfirst($currentPlan)),
                    ]
                );
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
            $subscription = $tenant->subscription('default');
            if ($subscription && $subscription->canceled()) {
                $planService = new PlanService();
                $currentPlan = $planService->getCurrentPlan($tenant);
                
                $subscription->resume();
                
                // Log activity
                ActivityRecorder::record(
                    tenant: $tenant,
                    eventType: EventType::SUBSCRIPTION_UPDATED,
                    actor: auth()->user(),
                    metadata: [
                        'action' => 'resumed',
                        'plan' => $currentPlan,
                        'plan_name' => config("plans.{$currentPlan}.name", ucfirst($currentPlan)),
                    ]
                );
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

            $invoices = $tenant->invoices(false, [
                'limit' => 24,
                'expand' => ['data.charge'],
            ]);

            return collect($invoices)->map(function ($invoice) {
                $paidCents = $invoice->rawAmountPaid();
                $refundedCents = $this->refundedAmountCentsForCashierInvoice($invoice);
                $netCents = max(0, $paidCents - $refundedCents);

                $stripeStatus = (string) ($invoice->status ?? 'unknown');
                $displayStatus = $stripeStatus;
                if ($refundedCents > 0 && $stripeStatus === 'paid' && $paidCents > 0) {
                    $displayStatus = $refundedCents >= $paidCents ? 'refunded' : 'partially_refunded';
                }

                return [
                    'id' => $invoice->id,
                    'amount' => $netCents / 100,
                    'amount_paid_gross' => $paidCents / 100,
                    'amount_refunded' => $refundedCents / 100,
                    'currency' => strtoupper($invoice->currency ?? 'usd'),
                    'status' => $displayStatus,
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
     * Refunds apply to the Stripe Charge; amount_paid on the invoice often stays unchanged.
     */
    protected function refundedAmountCentsForCashierInvoice(\Laravel\Cashier\Invoice $invoice): int
    {
        $charge = $invoice->charge;

        if ($charge instanceof \Stripe\Charge) {
            return (int) ($charge->amount_refunded ?? 0);
        }

        if (is_string($charge) && $charge !== '' && config('services.stripe.secret')) {
            try {
                $ch = \Stripe\Charge::retrieve($charge, [
                    'api_key' => config('services.stripe.secret'),
                ]);

                return (int) ($ch->amount_refunded ?? 0);
            } catch (\Throwable) {
                return 0;
            }
        }

        return 0;
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
            $this->ensureValidStripeCustomer($tenant);
            $tenant->updateDefaultPaymentMethod($paymentMethodId);
        } catch (BillingUserFacingException $e) {
            throw $e;
        } catch (ApiErrorException $e) {
            $ctx = ['tenant_id' => $tenant->id];
            $this->logStripeApiFailure('billing.payment_method.stripe_api_error', $e, $ctx);

            throw BillingUserFacingException::fromStripeApi($e, 'BILLING_PAYMENT_METHOD_FAILED', $ctx);
        }
    }

    /**
     * Get Stripe Customer Portal URL for self-service billing management.
     */
    public function getCustomerPortalUrl(Tenant $tenant, string $returnUrl): string
    {
        try {
            $this->ensureValidStripeCustomer($tenant);

            if (! $tenant->stripe_id) {
                throw new \RuntimeException('Tenant does not have a Stripe customer ID.');
            }

            return $tenant->billingPortalUrl($returnUrl);
        } catch (BillingUserFacingException $e) {
            throw $e;
        } catch (ApiErrorException $e) {
            $ctx = ['tenant_id' => $tenant->id];
            $this->logStripeApiFailure('billing.portal.stripe_api_error', $e, $ctx);

            throw BillingUserFacingException::fromStripeApi($e, 'BILLING_PORTAL_FAILED', $ctx);
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

    /**
     * Add a storage add-on to the tenant's subscription.
     * Only one storage add-on at a time; adding a new one replaces the previous.
     *
     * @param Tenant $tenant
     * @param string $packageId Package ID from config (e.g. storage_50gb)
     * @return array Updated storage info from PlanService
     * @throws \RuntimeException
     */
    public function addStorageAddon(Tenant $tenant, string $packageId): array
    {
        $packages = config('storage_addons.packages', []);
        $package = collect($packages)->firstWhere('id', $packageId);

        if (! $package) {
            throw new \InvalidArgumentException("Invalid storage add-on package: {$packageId}");
        }

        $stripePriceId = $package['stripe_price_id'] ?? null;
        if (empty($stripePriceId)) {
            throw new \RuntimeException("Stripe price not configured for storage add-on package: {$packageId}");
        }

        $subscription = $tenant->subscription('default');
        if (! $subscription || ! $subscription->stripe_id || $subscription->stripe_status !== 'active') {
            throw new \RuntimeException('Tenant must have an active subscription to add storage.');
        }

        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret)) {
            throw new \RuntimeException('Stripe is not configured.');
        }
        Stripe::setApiKey($stripeSecret);

        $idempotencyKey = "tenant-{$tenant->id}-storage-addon";

        try {
            // If tenant already has an add-on, remove it first
            if (! empty($tenant->storage_addon_stripe_subscription_item_id)) {
                $this->removeStorageAddonStripeItem($tenant->storage_addon_stripe_subscription_item_id);
            }

            $item = SubscriptionItem::create([
                'subscription' => $subscription->stripe_id,
                'price' => $stripePriceId,
                'quantity' => 1,
            ], [
                'idempotency_key' => $idempotencyKey,
            ]);

            $tenant->update([
                'storage_addon_mb' => $package['storage_mb'],
                'storage_addon_stripe_price_id' => $stripePriceId,
                'storage_addon_stripe_subscription_item_id' => $item->id,
            ]);

            return (new PlanService())->getStorageInfo($tenant);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to add storage add-on: ' . $e->getMessage());
        }
    }

    /**
     * Remove the storage add-on from the tenant's subscription.
     *
     * @param Tenant $tenant
     * @return array Updated storage info from PlanService
     * @throws \RuntimeException
     */
    public function removeStorageAddon(Tenant $tenant): array
    {
        $itemId = $tenant->storage_addon_stripe_subscription_item_id;

        if (empty($itemId)) {
            // No add-on to remove; return current storage info
            return (new PlanService())->getStorageInfo($tenant);
        }

        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret)) {
            throw new \RuntimeException('Stripe is not configured.');
        }
        Stripe::setApiKey($stripeSecret);

        try {
            $this->removeStorageAddonStripeItem($itemId);

            $tenant->update([
                'storage_addon_mb' => 0,
                'storage_addon_stripe_price_id' => null,
                'storage_addon_stripe_subscription_item_id' => null,
            ]);

            return (new PlanService())->getStorageInfo($tenant);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to remove storage add-on: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // AI Credit Add-on
    // -------------------------------------------------------------------------

    /**
     * Add an AI credit add-on to the tenant's subscription.
     * Only one credit add-on active at a time; adding a new one replaces the previous.
     */
    public function addAiCreditsAddon(Tenant $tenant, string $packageId): array
    {
        $packages = config('ai_credits.addons', []);
        $package = collect($packages)->firstWhere('id', $packageId);

        if (! $package) {
            throw new \InvalidArgumentException("Invalid AI credit add-on package: {$packageId}");
        }

        $stripePriceId = $package['stripe_price_id'] ?? null;
        if (empty($stripePriceId)) {
            throw new \RuntimeException("Stripe price not configured for AI credit add-on: {$packageId}");
        }

        $subscription = $tenant->subscription('default');
        if (! $subscription || ! $subscription->stripe_id || $subscription->stripe_status !== 'active') {
            throw new \RuntimeException('Tenant must have an active subscription to add AI credits.');
        }

        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret)) {
            throw new \RuntimeException('Stripe is not configured.');
        }
        Stripe::setApiKey($stripeSecret);

        try {
            if (! empty($tenant->ai_credits_addon_stripe_subscription_item_id)) {
                $this->removeStripeSubscriptionItem($tenant->ai_credits_addon_stripe_subscription_item_id);
            }

            $item = SubscriptionItem::create([
                'subscription' => $subscription->stripe_id,
                'price' => $stripePriceId,
                'quantity' => 1,
            ], [
                'idempotency_key' => "tenant-{$tenant->id}-ai-credits-addon",
            ]);

            $tenant->update([
                'ai_credits_addon' => $package['credits'],
                'ai_credits_addon_stripe_price_id' => $stripePriceId,
                'ai_credits_addon_stripe_subscription_item_id' => $item->id,
            ]);

            return [
                'credits_added' => $package['credits'],
                'package_id' => $packageId,
                'effective_credits' => (new PlanService())->getEffectiveAiCredits($tenant),
            ];
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to add AI credit add-on: '.$e->getMessage());
        }
    }

    /**
     * Remove the AI credit add-on from the tenant's subscription.
     */
    public function removeAiCreditsAddon(Tenant $tenant): array
    {
        $itemId = $tenant->ai_credits_addon_stripe_subscription_item_id;

        if (empty($itemId)) {
            return ['credits_added' => 0, 'effective_credits' => (new PlanService())->getEffectiveAiCredits($tenant)];
        }

        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret)) {
            throw new \RuntimeException('Stripe is not configured.');
        }
        Stripe::setApiKey($stripeSecret);

        try {
            $this->removeStripeSubscriptionItem($itemId);

            $tenant->update([
                'ai_credits_addon' => 0,
                'ai_credits_addon_stripe_price_id' => null,
                'ai_credits_addon_stripe_subscription_item_id' => null,
            ]);

            return ['credits_added' => 0, 'effective_credits' => (new PlanService())->getEffectiveAiCredits($tenant)];
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to remove AI credit add-on: '.$e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Creator Module Add-on
    // -------------------------------------------------------------------------

    /**
     * Purchase the Creator Module add-on for a Pro tenant.
     */
    public function addCreatorModule(Tenant $tenant): array
    {
        $config = config('creator_addon.base');
        $stripePriceId = $config['stripe_price_id'] ?? null;

        if (empty($stripePriceId)) {
            throw new \RuntimeException('Stripe price not configured for Creator Module.');
        }

        $subscription = $tenant->subscription('default');
        if (! $subscription || ! $subscription->stripe_id || $subscription->stripe_status !== 'active') {
            throw new \RuntimeException('Tenant must have an active subscription to add Creator Module.');
        }

        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret)) {
            throw new \RuntimeException('Stripe is not configured.');
        }
        Stripe::setApiKey($stripeSecret);

        try {
            $item = SubscriptionItem::create([
                'subscription' => $subscription->stripe_id,
                'price' => $stripePriceId,
                'quantity' => 1,
            ], [
                'idempotency_key' => "tenant-{$tenant->id}-creator-module",
            ]);

            $module = \App\Models\TenantModule::updateOrCreate(
                ['tenant_id' => $tenant->id, 'module_key' => \App\Models\TenantModule::KEY_CREATOR],
                [
                    'status' => 'active',
                    'seats_limit' => $config['included_seats'] ?? 25,
                    'stripe_price_id' => $stripePriceId,
                    'stripe_subscription_item_id' => $item->id,
                    'granted_by_admin' => false,
                    'expires_at' => null,
                ]
            );

            return ['status' => 'active', 'seats_limit' => $module->seats_limit];
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to add Creator Module: '.$e->getMessage());
        }
    }

    /**
     * Remove the Creator Module add-on.
     */
    public function removeCreatorModule(Tenant $tenant): array
    {
        $module = \App\Models\TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_key', \App\Models\TenantModule::KEY_CREATOR)
            ->first();

        if (! $module || empty($module->stripe_subscription_item_id)) {
            return ['status' => 'removed'];
        }

        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret)) {
            throw new \RuntimeException('Stripe is not configured.');
        }
        Stripe::setApiKey($stripeSecret);

        try {
            // Remove seat pack first if exists
            if (! empty($module->seat_pack_stripe_subscription_item_id)) {
                $this->removeStripeSubscriptionItem($module->seat_pack_stripe_subscription_item_id);
            }

            $this->removeStripeSubscriptionItem($module->stripe_subscription_item_id);

            $module->update([
                'status' => 'inactive',
                'stripe_price_id' => null,
                'stripe_subscription_item_id' => null,
                'seat_pack_stripe_price_id' => null,
                'seat_pack_stripe_subscription_item_id' => null,
            ]);

            return ['status' => 'removed'];
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to remove Creator Module: '.$e->getMessage());
        }
    }

    /**
     * Add a creator seat pack to the tenant's Creator Module.
     */
    public function addCreatorSeatPack(Tenant $tenant, string $packId): array
    {
        $packs = config('creator_addon.seat_packs', []);
        $pack = collect($packs)->firstWhere('id', $packId);

        if (! $pack) {
            throw new \InvalidArgumentException("Invalid creator seat pack: {$packId}");
        }

        $stripePriceId = $pack['stripe_price_id'] ?? null;
        if (empty($stripePriceId)) {
            throw new \RuntimeException("Stripe price not configured for seat pack: {$packId}");
        }

        $module = \App\Models\TenantModule::query()
            ->where('tenant_id', $tenant->id)
            ->where('module_key', \App\Models\TenantModule::KEY_CREATOR)
            ->whereIn('status', ['active', 'trial'])
            ->first();

        if (! $module) {
            $planService = new PlanService();
            $planName = $planService->getCurrentPlan($tenant);
            $plan = config("plans.{$planName}", config('plans.free'));
            $planIncludes = (bool) ($plan['creator_module_included'] ?? false);
            if (! $planIncludes) {
                throw new \RuntimeException('Creator Module must be active to add seat packs.');
            }
            $planSeats = (int) ($plan['creator_module_included_seats'] ?? 0);
            $module = \App\Models\TenantModule::create([
                'tenant_id' => $tenant->id,
                'module_key' => \App\Models\TenantModule::KEY_CREATOR,
                'status' => 'active',
                'expires_at' => null,
                'granted_by_admin' => false,
                'seats_limit' => max(0, $planSeats),
                'stripe_price_id' => null,
                'stripe_subscription_item_id' => null,
                'seat_pack_stripe_price_id' => null,
                'seat_pack_stripe_subscription_item_id' => null,
            ]);
        }

        $subscription = $tenant->subscription('default');
        if (! $subscription || ! $subscription->stripe_id || $subscription->stripe_status !== 'active') {
            throw new \RuntimeException('Tenant must have an active subscription.');
        }

        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret)) {
            throw new \RuntimeException('Stripe is not configured.');
        }
        Stripe::setApiKey($stripeSecret);

        try {
            if (! empty($module->seat_pack_stripe_subscription_item_id)) {
                $this->removeStripeSubscriptionItem($module->seat_pack_stripe_subscription_item_id);
            }

            $item = SubscriptionItem::create([
                'subscription' => $subscription->stripe_id,
                'price' => $stripePriceId,
                'quantity' => 1,
            ], [
                'idempotency_key' => "tenant-{$tenant->id}-creator-seats",
            ]);

            $baseSeats = (int) ($module->seats_limit ?? 25);
            $module->update([
                'seats_limit' => $baseSeats + $pack['seats'],
                'seat_pack_stripe_price_id' => $stripePriceId,
                'seat_pack_stripe_subscription_item_id' => $item->id,
            ]);

            return ['seats_limit' => $module->seats_limit, 'pack_id' => $packId];
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to add creator seat pack: '.$e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Delete a Stripe subscription item (storage add-on).
     */
    private function removeStorageAddonStripeItem(string $stripeSubscriptionItemId): void
    {
        $this->removeStripeSubscriptionItem($stripeSubscriptionItemId);
    }

    private function removeStripeSubscriptionItem(string $stripeSubscriptionItemId): void
    {
        SubscriptionItem::retrieve($stripeSubscriptionItemId)->delete();
    }
}
