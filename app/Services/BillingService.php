<?php

namespace App\Services;

use App\Enums\EventType;
use App\Exceptions\BillingUserFacingException;
use App\Models\Tenant;
use App\Services\Billing\StripeCustomerVerifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Subscription as CashierSubscription;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Subscription;
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
     * @param  string  $priceId  Stripe price ID
     * @param  bool  $allowMultipleSubscriptions  If false, will throw exception if subscription exists
     * @return string Checkout session URL
     *
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
     * Cashier calls $subscription->owner for swap(), tax payload, and Stripe API access.
     * The owner BelongsTo must match the billable model ({@see Tenant}) and tenant_id on subscriptions.
     */
    protected function assignCashierSubscriptionOwner(CashierSubscription $subscription, Tenant $tenant): void
    {
        if (method_exists($subscription, 'setOwner')) {
            $subscription->setOwner($tenant);

            return;
        }
        if (method_exists($subscription, 'setRelation')) {
            $subscription->setRelation('owner', $tenant);
        }
    }

    /**
     * Stripe price IDs that represent a base plan line (not storage / AI / creator add-ons).
     *
     * @return list<string>
     */
    protected function basePlanStripePriceIds(): array
    {
        $ids = [];
        foreach (config('plans', []) as $plan) {
            $pid = $plan['stripe_price_id'] ?? null;
            if (is_string($pid) && $pid !== '' && $pid !== 'price_free') {
                $ids[] = $pid;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * All recurring line price IDs on this subscription (parent row + subscription_items).
     *
     * @return list<string>
     */
    protected function subscriptionLinePriceIdsForSwap(CashierSubscription $subscription): array
    {
        $ids = [];
        if ($subscription->stripe_price) {
            $ids[] = (string) $subscription->stripe_price;
        }
        foreach ($subscription->items as $item) {
            if ($item->stripe_price) {
                $ids[] = (string) $item->stripe_price;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Cashier {@see CashierSubscription::swap()} rebuilds the whole Stripe item set. Passing only the
     * new plan price would mark add-on lines deleted. Keep non–base-plan prices and replace base plan
     * lines with the new price.
     *
     * @param  list<string>  $currentPriceIds
     * @return list<string>
     */
    protected function mergePlanChangePriceIds(array $currentPriceIds, string $newBasePlanPriceId): array
    {
        $base = $this->basePlanStripePriceIds();
        $keptAddons = array_values(array_filter(
            $currentPriceIds,
            static fn (string $p): bool => ! in_array($p, $base, true)
        ));

        return array_values(array_unique(array_merge([$newBasePlanPriceId], $keptAddons)));
    }

    /**
     * @return list<string>
     */
    protected function priceIdsForPlanChange(CashierSubscription $subscription, string $newBasePlanPriceId): array
    {
        $subscription->loadMissing('items');

        return $this->mergePlanChangePriceIds(
            $this->subscriptionLinePriceIdsForSwap($subscription),
            $newBasePlanPriceId
        );
    }

    /**
     * Monthly amount in dollars for any configured or Stripe price ID (for upgrade vs downgrade).
     */
    protected function getMonthlyAmountForPriceSelection(string $priceId, ?string $planKey): float
    {
        if ($planKey && $planKey !== 'unknown' && config("plans.{$planKey}")) {
            return $this->getPlanPrice($planKey);
        }

        return $this->getPlanPriceByStripePriceId($priceId);
    }

    /**
     * Update tenant subscription to a new plan.
     *
     * @param  string  $priceId  New Stripe price ID
     * @param  string|null  $oldPlanId  Old plan ID (for activity logging)
     * @param  string|null  $newPlanId  New plan ID (for activity logging)
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

            if ($wasSubscribed) {
                $this->assignCashierSubscriptionOwner($oldSubscription, $tenant);
            }

            // Check if subscription has incomplete payment
            // Only check if subscription has a Stripe customer (stripe_id) - forced plans may not have one
            if ($wasSubscribed && $oldSubscription->stripe_id && $tenant->stripe_id && $oldSubscription->hasIncompletePayment()) {
                // Get the latest payment for redirect URL
                $latestPayment = $oldSubscription->latestPayment();
                throw new \RuntimeException(
                    'Your subscription has an incomplete payment. Please complete your payment before changing plans. '.
                    ($latestPayment ? 'Payment ID: '.$latestPayment->id : '')
                );
            }

            // Get old plan info
            $oldPlan = $oldPlanId ?? (new PlanService)->getCurrentPlan($tenant);

            // Get price info to determine new plan
            if (! $newPlanId) {
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
                        // Block only states where Stripe will not complete a normal swap. past_due may still
                        // be updated (e.g. upgrade + invoice) once the default payment method works.
                        if (in_array($stripeSubscription->status, ['incomplete', 'incomplete_expired', 'unpaid'], true)) {
                            throw new \RuntimeException(
                                'Your subscription payment is incomplete. Please update your payment method and complete the payment before changing plans.'
                            );
                        }
                    } catch (\Stripe\Exception\ApiErrorException $e) {
                        // If we can't fetch from Stripe, proceed but might fail
                        // The swap() call below will throw an error if payment is incomplete
                    }
                }

                // Update existing subscription: one Stripe subscription update with proration (best practice).
                // Do not cancel + re-subscribe — that loses continuity and complicates tax/add-ons.
                $oldSubscription->loadMissing('items');
                $swapPriceIds = $this->priceIdsForPlanChange($oldSubscription, $priceId);

                $oldPrice = $this->getPlanPrice($oldPlan);
                $newPrice = $this->getMonthlyAmountForPriceSelection($priceId, $newPlanId);
                $isUpgrade = $newPrice > $oldPrice;

                Log::info('billing.subscription_update.swap', [
                    'tenant_id' => $tenant->id,
                    'old_plan' => $oldPlan,
                    'new_plan' => $newPlanId ?? 'unknown',
                    'swap_price_ids' => $swapPriceIds,
                    'is_upgrade' => $isUpgrade,
                ]);

                // Upgrades: finalize prorations into an invoice and attempt payment (strong default for SCA).
                // Downgrades / lateral: proration credits apply per Stripe defaults; avoid forcing a payment attempt.
                if ($isUpgrade) {
                    $oldSubscription->swapAndInvoice($swapPriceIds);
                } else {
                    $oldSubscription->swap($swapPriceIds);
                }

                if ($newPrice > $oldPrice) {
                    $action = 'upgrade';
                } elseif ($newPrice < $oldPrice) {
                    $action = 'downgrade';
                } else {
                    $action = 'updated';
                }
            }

            app(PlanService::class)->forgetCurrentPlanCache($tenant);

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
            throw new \RuntimeException('Payment incomplete: '.$e->getMessage());
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
        if (! $plan) {
            return 0;
        }

        $priceId = $plan['stripe_price_id'] ?? null;
        if (! $priceId || $priceId === 'price_free') {
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
     * Unit amount (dollars) for a Stripe price ID when plan key is unknown.
     */
    private function getPlanPriceByStripePriceId(string $priceId): float
    {
        try {
            $stripeSecret = config('services.stripe.secret');
            if (! $stripeSecret) {
                return 0;
            }
            Stripe::setApiKey($stripeSecret);
            $price = Price::retrieve($priceId);

            return ($price->unit_amount ?? 0) / 100;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Live Stripe invoices for this customer (admin diagnostics). Not stored locally.
     *
     * @return list<array<string, mixed>>
     */
    public function listStripeInvoicesForAdmin(Tenant $tenant, int $limit = 40): array
    {
        $secret = config('services.stripe.secret');
        if (empty($secret) || empty($tenant->stripe_id)) {
            return [];
        }

        $client = new StripeClient($secret);
        $list = $client->invoices->all([
            'customer' => $tenant->stripe_id,
            'limit' => $limit,
        ]);

        $rows = [];
        foreach ($list->data as $inv) {
            $desc = $inv->description ?? null;
            if (! $desc && ! empty($inv->lines->data)) {
                $first = $inv->lines->data[0];
                $desc = $first->description ?? null;
            }
            $desc = $desc ?: 'Subscription invoice';

            $subscriptionId = $inv->subscription ?? null;
            if (is_object($subscriptionId) && isset($subscriptionId->id)) {
                $subscriptionId = $subscriptionId->id;
            }

            $rows[] = [
                'id' => $inv->id,
                'number' => $inv->number ?? null,
                'status' => $inv->status,
                'amount_paid' => ($inv->amount_paid ?? 0) / 100,
                'amount_due' => ($inv->amount_due ?? 0) / 100,
                'currency' => strtoupper($inv->currency ?? 'usd'),
                'created' => $inv->created,
                'hosted_invoice_url' => $inv->hosted_invoice_url,
                'invoice_pdf' => $inv->invoice_pdf ?? null,
                'description' => Str::limit($desc, 140),
                'subscription_id' => is_string($subscriptionId) ? $subscriptionId : null,
                'can_void_in_stripe' => in_array($inv->status, ['draft', 'open'], true),
            ];
        }

        return $rows;
    }

    /**
     * Void a draft or open invoice in Stripe for this tenant's customer.
     *
     * @throws \RuntimeException
     */
    public function voidStripeInvoiceForTenant(Tenant $tenant, string $invoiceId): void
    {
        $secret = config('services.stripe.secret');
        if (empty($secret) || empty($tenant->stripe_id)) {
            throw new \RuntimeException('Stripe is not configured or this company has no Stripe customer.');
        }

        if (! str_starts_with($invoiceId, 'in_')) {
            throw new \RuntimeException('Invalid invoice id.');
        }

        $client = new StripeClient($secret);
        $inv = $client->invoices->retrieve($invoiceId, []);

        if (($inv->customer ?? null) !== $tenant->stripe_id) {
            throw new \RuntimeException('That invoice does not belong to this company’s Stripe customer.');
        }

        if (! in_array($inv->status, ['draft', 'open'], true)) {
            throw new \RuntimeException(
                'Only draft or open invoices can be voided from here. Paid or finalized invoices must be handled in the Stripe Dashboard (credit note / refund).'
            );
        }

        try {
            $client->invoices->voidInvoice($invoiceId, []);
        } catch (ApiErrorException $e) {
            $this->logStripeApiFailure('billing.admin_void_invoice', $e, [
                'tenant_id' => $tenant->id,
                'invoice_id' => $invoiceId,
            ]);

            throw new \RuntimeException('Stripe could not void this invoice: '.$e->getMessage());
        }
    }

    /**
     * Cancel tenant subscription.
     */
    public function cancelSubscription(Tenant $tenant): void
    {
        try {
            if ($tenant->subscribed()) {
                $subscription = $tenant->subscription('default');
                $planService = new PlanService;
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
            throw new \RuntimeException('Failed to cancel subscription: '.$e->getMessage());
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
                $planService = new PlanService;
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
            throw new \RuntimeException('Failed to resume subscription: '.$e->getMessage());
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
        $planService = new PlanService;

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
     * @param  string  $invoiceId  Stripe invoice ID
     * @param  int|null  $amount  Amount in cents (null for full refund)
     * @param  string|null  $reason  Reason for refund
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
            throw new \RuntimeException('Failed to process refund: '.$e->getMessage());
        }
    }

    /**
     * Add a storage add-on to the tenant's subscription.
     * Only one storage add-on at a time; adding a new one replaces the previous.
     *
     * @param  string  $packageId  Package ID from config (e.g. storage_50gb)
     * @return array Updated storage info from PlanService
     *
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

        // Stripe binds idempotency keys to the exact request body; a fixed key breaks after
        // subscription/price changes, config changes, or a retry with a different create payload.
        $idempotencyKey = sprintf(
            't%d-stg-addon-%s-%s-%s',
            $tenant->id,
            $subscription->stripe_id,
            $stripePriceId,
            Str::lower((string) Str::ulid())
        );

        try {
            // If tenant already has an add-on, remove it first
            if (! empty($tenant->storage_addon_stripe_subscription_item_id)) {
                $this->removeStorageAddonStripeItem($tenant->storage_addon_stripe_subscription_item_id);
            }

            $item = $this->createSubscriptionAddonItem([
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

            try {
                $stripeSub = Subscription::retrieve($subscription->stripe_id, [
                    'expand' => ['latest_invoice'],
                ]);
                Log::info('billing.storage_addon.added', [
                    'tenant_id' => $tenant->id,
                    'package_id' => $packageId,
                    'stripe_price_id' => $stripePriceId,
                    'subscription_item_id' => $item->id,
                    'latest_invoice_id' => $stripeSub->latest_invoice->id ?? null,
                    'latest_invoice_status' => $stripeSub->latest_invoice->status ?? null,
                    'latest_invoice_amount_paid' => $stripeSub->latest_invoice->amount_paid ?? null,
                ]);
            } catch (\Throwable $t) {
                Log::warning('billing.storage_addon.added_invoice_snapshot_failed', [
                    'tenant_id' => $tenant->id,
                    'message' => $t->getMessage(),
                ]);
            }

            return (new PlanService)->getStorageInfo($tenant);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to add storage add-on: '.$e->getMessage());
        }
    }

    /**
     * Remove the storage add-on from the tenant's subscription.
     *
     * @return array Updated storage info from PlanService
     *
     * @throws \RuntimeException
     */
    public function removeStorageAddon(Tenant $tenant): array
    {
        $itemId = $tenant->storage_addon_stripe_subscription_item_id;

        if (empty($itemId)) {
            // No add-on to remove; return current storage info
            return (new PlanService)->getStorageInfo($tenant);
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

            return (new PlanService)->getStorageInfo($tenant);
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Failed to remove storage add-on: '.$e->getMessage());
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

            $item = $this->createSubscriptionAddonItem([
                'subscription' => $subscription->stripe_id,
                'price' => $stripePriceId,
                'quantity' => 1,
            ], [
                'idempotency_key' => sprintf(
                    't%d-ai-credits-%s-%s-%s',
                    $tenant->id,
                    $subscription->stripe_id,
                    $stripePriceId,
                    Str::lower((string) Str::ulid())
                ),
            ]);

            $tenant->update([
                'ai_credits_addon' => $package['credits'],
                'ai_credits_addon_stripe_price_id' => $stripePriceId,
                'ai_credits_addon_stripe_subscription_item_id' => $item->id,
            ]);

            return [
                'credits_added' => $package['credits'],
                'package_id' => $packageId,
                'effective_credits' => (new PlanService)->getEffectiveAiCredits($tenant),
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
            return ['credits_added' => 0, 'effective_credits' => (new PlanService)->getEffectiveAiCredits($tenant)];
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

            return ['credits_added' => 0, 'effective_credits' => (new PlanService)->getEffectiveAiCredits($tenant)];
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
            $item = $this->createSubscriptionAddonItem([
                'subscription' => $subscription->stripe_id,
                'price' => $stripePriceId,
                'quantity' => 1,
            ], [
                'idempotency_key' => sprintf(
                    't%d-creator-module-%s-%s-%s',
                    $tenant->id,
                    $subscription->stripe_id,
                    $stripePriceId,
                    Str::lower((string) Str::ulid())
                ),
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
            $planService = new PlanService;
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

            $item = $this->createSubscriptionAddonItem([
                'subscription' => $subscription->stripe_id,
                'price' => $stripePriceId,
                'quantity' => 1,
            ], [
                'idempotency_key' => sprintf(
                    't%d-creator-seats-%s-%s-%s-%s',
                    $tenant->id,
                    preg_replace('/[^A-Za-z0-9_-]/', '-', $packId),
                    $subscription->stripe_id,
                    $stripePriceId,
                    Str::lower((string) Str::ulid())
                ),
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
     * Recurring add-ons: invoice prorations immediately and require payment on the default payment method.
     *
     * Stripe SubscriptionItem create: {@code proration_behavior: always_invoice} finalizes prorations into an
     * invoice now; {@code payment_behavior: error_if_incomplete} fails the request if that invoice cannot be
     * paid (card declined, etc.) so entitlements stay aligned with successful collection.
     */
    private function createSubscriptionAddonItem(array $params, array $requestOptions = []): \Stripe\SubscriptionItem
    {
        return SubscriptionItem::create(array_merge($params, [
            'proration_behavior' => 'always_invoice',
            'payment_behavior' => 'error_if_incomplete',
        ]), $requestOptions);
    }

    /**
     * Fix drift between tenant add-on columns and Stripe subscription items.
     *
     * Clears local storage / AI credit add-on fields when:
     * - Extra MB or price IDs exist without a subscription item ID (orphaned DB row), or
     * - The stored subscription item ID is missing in Stripe, or
     * - The stored subscription item ID is not on the tenant's default Cashier subscription.
     *
     * Invoices shown in the product UI come from Stripe via Cashier — they cannot be deleted here.
     *
     * @return array{ok: bool, cleared: array{storage: bool, ai_credits: bool}, messages: list<string>}
     */
    public function reconcileTenantStripeAddonColumns(Tenant $tenant): array
    {
        $cleared = ['storage' => false, 'ai_credits' => false];
        $messages = [];

        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret)) {
            return [
                'ok' => false,
                'cleared' => $cleared,
                'messages' => ['Stripe is not configured in this environment.'],
            ];
        }

        Stripe::setApiKey($stripeSecret);
        $tenant->refresh();

        $itemIdsOnSubscription = [];
        $cashierSub = $tenant->subscription('default');
        $hasQueryableSubscription = $cashierSub
            && $cashierSub->stripe_id
            && in_array($cashierSub->stripe_status, ['active', 'trialing', 'past_due'], true);

        $subscriptionItemsResolved = false;
        if ($hasQueryableSubscription) {
            try {
                $stripeSub = Subscription::retrieve($cashierSub->stripe_id, [
                    'expand' => ['items.data.price'],
                ]);
                foreach ($stripeSub->items->data as $item) {
                    $itemIdsOnSubscription[] = $item->id;
                }
                $subscriptionItemsResolved = true;
            } catch (ApiErrorException $e) {
                $messages[] = 'Could not load Stripe subscription to compare items: '.$e->getMessage();
            }
        }

        // --- Storage add-on ---
        $storageItemId = $tenant->storage_addon_stripe_subscription_item_id;
        $storageOrphanLocal = ($tenant->storage_addon_mb > 0 || ! empty($tenant->storage_addon_stripe_price_id))
            && empty($storageItemId);

        if ($storageOrphanLocal) {
            $tenant->forceFill([
                'storage_addon_mb' => 0,
                'storage_addon_stripe_price_id' => null,
                'storage_addon_stripe_subscription_item_id' => null,
            ])->save();
            $cleared['storage'] = true;
            $messages[] = 'Cleared orphaned storage add-on fields (extra quota or price ID without a Stripe subscription item).';
        } elseif (! empty($storageItemId)) {
            $shouldClear = false;

            if ($subscriptionItemsResolved) {
                if (! in_array($storageItemId, $itemIdsOnSubscription, true)) {
                    $shouldClear = true;
                }
            } else {
                try {
                    SubscriptionItem::retrieve($storageItemId);
                    if (! $hasQueryableSubscription) {
                        $messages[] = 'Storage add-on item still exists in Stripe, but this tenant has no active default subscription in the database — try “Sync subscription from Stripe” first.';
                    }
                } catch (ApiErrorException $e) {
                    if ($this->stripeSubscriptionItemAlreadyRemoved($e)) {
                        $shouldClear = true;
                    } else {
                        $messages[] = 'Could not verify storage add-on item: '.$e->getMessage();
                    }
                }
            }

            if ($shouldClear) {
                $tenant->forceFill([
                    'storage_addon_mb' => 0,
                    'storage_addon_stripe_price_id' => null,
                    'storage_addon_stripe_subscription_item_id' => null,
                ])->save();
                $cleared['storage'] = true;
                $messages[] = 'Cleared storage add-on fields — the Stripe subscription item is gone or not on this subscription.';
            }
        }

        $tenant->refresh();

        // --- AI credits add-on ---
        $aiItemId = $tenant->ai_credits_addon_stripe_subscription_item_id;
        $aiOrphanLocal = ($tenant->ai_credits_addon > 0 || ! empty($tenant->ai_credits_addon_stripe_price_id))
            && empty($aiItemId);

        if ($aiOrphanLocal) {
            $tenant->forceFill([
                'ai_credits_addon' => 0,
                'ai_credits_addon_stripe_price_id' => null,
                'ai_credits_addon_stripe_subscription_item_id' => null,
            ])->save();
            $cleared['ai_credits'] = true;
            $messages[] = 'Cleared orphaned AI credits add-on fields (credits or price ID without a Stripe subscription item).';
        } elseif (! empty($aiItemId)) {
            $shouldClearAi = false;

            if ($subscriptionItemsResolved) {
                if (! in_array($aiItemId, $itemIdsOnSubscription, true)) {
                    $shouldClearAi = true;
                }
            } else {
                try {
                    SubscriptionItem::retrieve($aiItemId);
                    if (! $hasQueryableSubscription) {
                        $messages[] = 'AI credits add-on item still exists in Stripe, but this tenant has no active default subscription in the database — try “Sync subscription from Stripe” first.';
                    }
                } catch (ApiErrorException $e) {
                    if ($this->stripeSubscriptionItemAlreadyRemoved($e)) {
                        $shouldClearAi = true;
                    } else {
                        $messages[] = 'Could not verify AI credits add-on item: '.$e->getMessage();
                    }
                }
            }

            if ($shouldClearAi) {
                $tenant->forceFill([
                    'ai_credits_addon' => 0,
                    'ai_credits_addon_stripe_price_id' => null,
                    'ai_credits_addon_stripe_subscription_item_id' => null,
                ])->save();
                $cleared['ai_credits'] = true;
                $messages[] = 'Cleared AI credits add-on fields — the Stripe subscription item is gone or not on this subscription.';
            }
        }

        if ($messages === []) {
            $messages[] = 'No orphaned add-on fields detected; tenant columns match the active Stripe subscription.';
        }

        return ['ok' => true, 'cleared' => $cleared, 'messages' => $messages];
    }

    /**
     * Delete a Stripe subscription item (storage add-on).
     */
    private function removeStorageAddonStripeItem(string $stripeSubscriptionItemId): void
    {
        $this->removeStripeSubscriptionItem($stripeSubscriptionItemId);
    }

    private function removeStripeSubscriptionItem(string $stripeSubscriptionItemId): void
    {
        try {
            SubscriptionItem::retrieve($stripeSubscriptionItemId)->delete();
        } catch (ApiErrorException $e) {
            if ($this->stripeSubscriptionItemAlreadyRemoved($e)) {
                Log::warning('billing.stripe_subscription_item.already_removed', [
                    'subscription_item_id' => $stripeSubscriptionItemId,
                    'stripe_code' => $e->getStripeCode(),
                ]);

                return;
            }

            throw $e;
        }
    }

    /**
     * True when Stripe has no such item (Dashboard delete, new subscription, env mismatch, etc.).
     */
    private function stripeSubscriptionItemAlreadyRemoved(ApiErrorException $e): bool
    {
        if ($e->getHttpStatus() === 404) {
            return true;
        }

        $code = $e->getStripeCode();
        if ($code === 'resource_missing') {
            return true;
        }

        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'no such subscription_item')
            || str_contains($msg, 'invalid subscription_item');
    }
}
