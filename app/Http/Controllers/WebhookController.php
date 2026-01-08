<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierController;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends CashierController
{
    /**
     * Get the billable entity instance by Stripe ID.
     * Override to use Tenant instead of User.
     */
    protected function getUserByStripeId($stripeId)
    {
        if (!$stripeId) {
            return null;
        }
        
        return Tenant::where('stripe_id', $stripeId)->first();
    }

    /**
     * Handle incoming Stripe webhooks.
     * Override to handle all events ourselves since we use Tenant instead of User.
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->all();
        $eventType = $payload['type'] ?? null;
        $eventId = $payload['id'] ?? null;

        try {
            // Log the webhook event for debugging (only log, don't fail on logging errors)
            try {
                \Log::info('Stripe webhook received', [
                    'type' => $eventType,
                    'id' => $eventId,
                ]);
            } catch (\Exception $logError) {
                // Ignore logging errors
            }

            // Route to appropriate handler based on event type
            $response = match ($eventType) {
                'checkout.session.completed' => $this->handleCheckoutSessionCompleted($payload),
                'customer.subscription.created' => $this->handleCustomerSubscriptionCreated($payload),
                'customer.subscription.updated' => $this->handleCustomerSubscriptionUpdated($payload),
                'customer.subscription.deleted' => $this->handleCustomerSubscriptionDeleted($payload),
                'invoice.created' => $this->handleInvoiceCreated($payload),
                'invoice.finalized' => $this->handleInvoiceFinalized($payload),
                'invoice.paid' => $this->handleInvoicePaid($payload),
                'invoice.payment_succeeded' => $this->handleInvoicePaymentSucceeded($payload),
                'invoice.payment_failed' => $this->handleInvoicePaymentFailed($payload),
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($payload),
                'payment_intent.created' => $this->handlePaymentIntentCreated($payload),
                'charge.succeeded' => $this->handleChargeSucceeded($payload),
                'charge.refunded' => $this->handleChargeRefunded($payload),
                'payment_method.attached' => $this->handlePaymentMethodAttached($payload),
                default => $this->handleUnknownWebhook($payload),
            };

            // Log success
            try {
                \Log::info('Stripe webhook processed successfully', [
                    'type' => $eventType,
                    'id' => $eventId,
                ]);
            } catch (\Exception $logError) {
                // Ignore logging errors
            }

            return $response;
        } catch (\Throwable $e) {
            // Catch both Exception and Error (including ErrorException in PHP 8+)
            try {
                \Log::error('Error handling Stripe webhook', [
                    'type' => $eventType,
                    'id' => $eventId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => substr($e->getTraceAsString(), 0, 1000), // Limit trace length
                ]);
            } catch (\Exception $logError) {
                // Even logging failed, but we still need to return success
            }

            // Return success to prevent Stripe from retrying
            // This prevents webhook failures from blocking Stripe operations
            return $this->successMethod();
        }
    }

    /**
     * Handle unknown webhook events.
     */
    protected function handleUnknownWebhook(array $payload): Response
    {
        $eventType = $payload['type'] ?? 'unknown';
        \Log::info('Unhandled webhook event type', [
            'type' => $eventType,
            'id' => $payload['id'] ?? null,
        ]);

        return $this->successMethod();
    }

    /**
     * Handle checkout session completed.
     * This is called when a customer completes checkout, and we need to ensure
     * the subscription is properly synced.
     */
    protected function handleCheckoutSessionCompleted(array $payload): Response
    {
        $session = $payload['data']['object'];
        $stripeCustomerId = $session['customer'] ?? null;
        
        if ($stripeCustomerId) {
            $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();
            
            if ($tenant) {
                // If there's a subscription in the session, fetch and sync it
                if (isset($session['subscription'])) {
                    try {
                        $stripeSubscriptionId = $session['subscription'];
                        // Fetch the subscription from Stripe and sync it
                        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
                        $stripeSubscription = \Stripe\Subscription::retrieve($stripeSubscriptionId);
                        $this->syncSubscription($tenant, $stripeSubscription);
                    } catch (\Exception $e) {
                        \Log::error('Error syncing subscription from checkout session: ' . $e->getMessage(), [
                            'exception' => $e,
                            'stripe_subscription_id' => $session['subscription'] ?? null,
                        ]);
                    }
                }
            }
        }

        return $this->successMethod();
    }

    /**
     * Handle customer subscription created.
     * Manually sync subscription since we use Tenant instead of User.
     */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        $subscription = $payload['data']['object'];
        $stripeCustomerId = $subscription['customer'] ?? null;
        
        if (!$stripeCustomerId) {
            \Log::warning('customer.subscription.created webhook missing customer ID');
            return $this->successMethod();
        }

        $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();

        if (!$tenant) {
            \Log::warning('customer.subscription.created webhook - tenant not found', [
                'stripe_customer_id' => $stripeCustomerId,
            ]);
            return $this->successMethod();
        }

        try {
            // Sync the subscription using the payload data directly
            $this->syncSubscription($tenant, $subscription);
            
            // Refresh tenant to get latest subscription
            $tenant->refresh();
            
            \Log::info('Subscription created and synced', [
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription['id'],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error syncing subscription after creation', [
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription['id'] ?? null,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        return $this->successMethod();
    }

    /**
     * Sync a subscription from Stripe data to the database.
     */
    protected function syncSubscription(Tenant $tenant, $stripeSubscription): void
    {
        // Handle both array (from payload) and object (from API) formats
        $subscriptionId = is_array($stripeSubscription) ? $stripeSubscription['id'] : $stripeSubscription->id;
        $status = is_array($stripeSubscription) ? $stripeSubscription['status'] : $stripeSubscription->status;
        
        // Safely get items - handle both array and object formats
        if (is_array($stripeSubscription)) {
            $items = $stripeSubscription['items']['data'] ?? [];
        } else {
            $items = $stripeSubscription->items->data ?? [];
        }
        
        $trialEnd = is_array($stripeSubscription) ? ($stripeSubscription['trial_end'] ?? null) : ($stripeSubscription->trial_end ?? null);
        $cancelAt = is_array($stripeSubscription) ? ($stripeSubscription['cancel_at'] ?? null) : ($stripeSubscription->cancel_at ?? null);

        // Get or create the subscription
        $subscription = $tenant->subscriptions()->firstOrNew([
            'stripe_id' => $subscriptionId,
        ]);

        $subscription->name = 'default';
        $subscription->stripe_status = $status;
        
        // Safely get price and quantity from first item if it exists
        if (!empty($items)) {
            $firstItem = $items[0];
            if (is_array($firstItem)) {
                $subscription->stripe_price = $firstItem['price']['id'] ?? null;
                $subscription->quantity = $firstItem['quantity'] ?? 1;
            } else {
                $subscription->stripe_price = $firstItem->price->id ?? null;
                $subscription->quantity = $firstItem->quantity ?? 1;
            }
        } else {
            // No items found - this shouldn't happen but handle gracefully
            $subscription->stripe_price = null;
            $subscription->quantity = 1;
            \Log::warning('Subscription has no items', [
                'subscription_id' => $subscriptionId,
                'tenant_id' => $tenant->id,
            ]);
        }
        
        $subscription->trial_ends_at = $trialEnd 
            ? \Carbon\Carbon::createFromTimestamp($trialEnd) 
            : null;
        $subscription->ends_at = $cancelAt 
            ? \Carbon\Carbon::createFromTimestamp($cancelAt) 
            : null;

        $subscription->save();

        // Sync subscription items
        foreach ($items as $item) {
            try {
                $itemId = is_array($item) ? ($item['id'] ?? null) : ($item->id ?? null);
                $itemPrice = is_array($item) ? ($item['price'] ?? null) : ($item->price ?? null);
                $itemQuantity = is_array($item) ? ($item['quantity'] ?? 1) : ($item->quantity ?? 1);
                
                if (!$itemId || !$itemPrice) {
                    \Log::warning('Skipping subscription item - missing ID or price', [
                        'subscription_id' => $subscriptionId,
                        'item' => $item,
                    ]);
                    continue;
                }
                
                $subscriptionItem = $subscription->items()->firstOrNew([
                    'stripe_id' => $itemId,
                ]);

                $subscriptionItem->stripe_product = is_array($itemPrice) ? ($itemPrice['product'] ?? null) : ($itemPrice->product ?? null);
                $subscriptionItem->stripe_price = is_array($itemPrice) ? ($itemPrice['id'] ?? null) : ($itemPrice->id ?? null);
                $subscriptionItem->quantity = $itemQuantity;
                $subscriptionItem->save();
            } catch (\Exception $e) {
                \Log::error('Error syncing subscription item', [
                    'subscription_id' => $subscriptionId,
                    'error' => $e->getMessage(),
                    'item' => is_array($item) ? $item : (array) $item,
                ]);
            }
        }
    }

    /**
     * Handle customer subscription updated.
     */
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        $subscription = $payload['data']['object'];
        $stripeCustomerId = $subscription['customer'] ?? null;
        
        if (!$stripeCustomerId) {
            return $this->successMethod();
        }

        $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();

        if ($tenant) {
            try {
                // Sync the subscription
                $this->syncSubscription($tenant, $subscription);
                
                \Log::info('Subscription updated and synced', [
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription['id'],
                ]);
            } catch (\Exception $e) {
                \Log::error('Error syncing subscription update', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->successMethod();
    }

    /**
     * Handle customer subscription deleted.
     */
    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $subscription = $payload['data']['object'];
        $stripeCustomerId = $subscription['customer'] ?? null;
        $stripeSubscriptionId = $subscription['id'] ?? null;
        
        if (!$stripeCustomerId || !$stripeSubscriptionId) {
            return $this->successMethod();
        }

        $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();

        if ($tenant) {
            try {
                // Find and delete the subscription
                $subscriptionModel = $tenant->subscriptions()->where('stripe_id', $stripeSubscriptionId)->first();
                if ($subscriptionModel) {
                    $subscriptionModel->delete();
                    
                    \Log::info('Subscription deleted', [
                        'tenant_id' => $tenant->id,
                        'subscription_id' => $stripeSubscriptionId,
                    ]);
                }
            } catch (\Exception $e) {
                \Log::error('Error deleting subscription', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->successMethod();
    }

    /**
     * Handle invoice payment succeeded.
     */
    protected function handleInvoicePaymentSucceeded(array $payload): Response
    {
        $invoice = $payload['data']['object'];
        $stripeCustomerId = $invoice['customer'] ?? null;
        
        if ($stripeCustomerId) {
            $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();
            if ($tenant) {
                \Log::info('Invoice payment succeeded', [
                    'tenant_id' => $tenant->id,
                    'invoice_id' => $invoice['id'] ?? null,
                ]);
                // Invoice is automatically handled by Cashier's Billable trait
                // Additional logic can be added here if needed
            }
        }

        return $this->successMethod();
    }

    /**
     * Handle invoice payment failed.
     */
    protected function handleInvoicePaymentFailed(array $payload): Response
    {
        $invoice = $payload['data']['object'];
        $stripeCustomerId = $invoice['customer'] ?? null;
        
        if ($stripeCustomerId) {
            $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();
            if ($tenant) {
                \Log::warning('Invoice payment failed', [
                    'tenant_id' => $tenant->id,
                    'invoice_id' => $invoice['id'] ?? null,
                ]);
                // Payment failure is automatically handled by Cashier
                // Additional logic can be added here (e.g., send notification)
            }
        }

        return $this->successMethod();
    }

    /**
     * Handle invoice created webhook.
     */
    protected function handleInvoiceCreated(array $payload): Response
    {
        // Invoice creation is handled automatically by Cashier
        // Just log it for tracking
        $invoice = $payload['data']['object'];
        $stripeCustomerId = $invoice['customer'] ?? null;
        
        if ($stripeCustomerId) {
            $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();
            if ($tenant) {
                \Log::info('Invoice created', [
                    'tenant_id' => $tenant->id,
                    'invoice_id' => $invoice['id'] ?? null,
                ]);
            }
        }

        return $this->successMethod();
    }

    /**
     * Handle invoice finalized webhook.
     */
    protected function handleInvoiceFinalized(array $payload): Response
    {
        // Invoice finalization is handled automatically by Cashier
        $invoice = $payload['data']['object'];
        $stripeCustomerId = $invoice['customer'] ?? null;
        
        if ($stripeCustomerId) {
            $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();
            if ($tenant) {
                \Log::info('Invoice finalized', [
                    'tenant_id' => $tenant->id,
                    'invoice_id' => $invoice['id'] ?? null,
                ]);
            }
        }

        return $this->successMethod();
    }

    /**
     * Handle invoice paid webhook.
     */
    protected function handleInvoicePaid(array $payload): Response
    {
        // Invoice payment is handled automatically by Cashier
        // This is similar to invoice.payment_succeeded but triggered at a different point
        return $this->handleInvoicePaymentSucceeded($payload);
    }

    /**
     * Handle payment intent succeeded webhook.
     */
    protected function handlePaymentIntentSucceeded(array $payload): Response
    {
        $paymentIntent = $payload['data']['object'];
        $stripeCustomerId = $paymentIntent['customer'] ?? null;
        
        if ($stripeCustomerId) {
            $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();
            if ($tenant) {
                \Log::info('Payment intent succeeded', [
                    'tenant_id' => $tenant->id,
                    'payment_intent_id' => $paymentIntent['id'] ?? null,
                ]);
            }
        }

        return $this->successMethod();
    }

    /**
     * Handle payment intent created webhook.
     */
    protected function handlePaymentIntentCreated(array $payload): Response
    {
        // Payment intent creation is just informational
        // No action needed, just log it
        $paymentIntent = $payload['data']['object'];
        \Log::info('Payment intent created', [
            'payment_intent_id' => $paymentIntent['id'] ?? null,
        ]);

        return $this->successMethod();
    }

    /**
     * Handle charge succeeded webhook.
     */
    protected function handleChargeSucceeded(array $payload): Response
    {
        $charge = $payload['data']['object'];
        $stripeCustomerId = $charge['customer'] ?? null;
        
        if ($stripeCustomerId) {
            $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();
            if ($tenant) {
                \Log::info('Charge succeeded', [
                    'tenant_id' => $tenant->id,
                    'charge_id' => $charge['id'] ?? null,
                ]);
            }
        }

        return $this->successMethod();
    }

    /**
     * Handle payment method attached webhook.
     */
    protected function handlePaymentMethodAttached(array $payload): Response
    {
        $paymentMethod = $payload['data']['object'];
        $stripeCustomerId = $paymentMethod['customer'] ?? null;
        
        if ($stripeCustomerId) {
            $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();
            if ($tenant) {
                \Log::info('Payment method attached', [
                    'tenant_id' => $tenant->id,
                    'payment_method_id' => $paymentMethod['id'] ?? null,
                ]);
            }
        }

        return $this->successMethod();
    }

    /**
     * Handle charge refunded webhook.
     * This is called when a refund is processed in Stripe.
     */
    protected function handleChargeRefunded(array $payload): Response
    {
        try {
            $charge = $payload['data']['object'];
            $stripeCustomerId = $charge['customer'] ?? null;
            
            if ($stripeCustomerId) {
                $tenant = Tenant::where('stripe_id', $stripeCustomerId)->first();
                
                if ($tenant) {
                    \Log::info('Refund processed via webhook', [
                        'tenant_id' => $tenant->id,
                        'tenant_name' => $tenant->name,
                        'charge_id' => $charge['id'],
                        'refund_id' => $charge['refunds']['data'][0]['id'] ?? null,
                        'amount' => $charge['amount_refunded'] / 100,
                        'currency' => strtoupper($charge['currency']),
                    ]);

                    // You can add additional logic here:
                    // - Update subscription status if needed
                    // - Send notification to customer
                    // - Update internal records
                    // - Trigger any business logic
                }
            }
        } catch (\Exception $e) {
            \Log::error('Error handling charge refunded: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }

        return $this->successMethod();
    }
}
