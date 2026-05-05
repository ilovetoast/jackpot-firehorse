<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Services\PlanService;
use Illuminate\Support\Facades\Log;

/**
 * Persists Stripe subscription state to Cashier tables (subscriptions + items).
 * Shared by Stripe webhooks and the post-checkout success redirect.
 */
class StripeSubscriptionSyncService
{
    /**
     * @param  array<string, mixed>|\Stripe\Subscription  $stripeSubscription
     */
    public function sync(Tenant $tenant, array|\Stripe\Subscription $stripeSubscription): void
    {
        $planService = app(PlanService::class);
        $planService->forgetCurrentPlanCache($tenant);
        $planBefore = $planService->getCurrentPlan($tenant);

        $subscriptionId = is_array($stripeSubscription) ? $stripeSubscription['id'] : $stripeSubscription->id;
        $status = is_array($stripeSubscription) ? $stripeSubscription['status'] : $stripeSubscription->status;

        if (is_array($stripeSubscription)) {
            $items = $stripeSubscription['items']['data'] ?? [];
        } else {
            $items = $stripeSubscription->items->data ?? [];
        }

        $trialEnd = is_array($stripeSubscription) ? ($stripeSubscription['trial_end'] ?? null) : ($stripeSubscription->trial_end ?? null);
        $cancelAt = is_array($stripeSubscription) ? ($stripeSubscription['cancel_at'] ?? null) : ($stripeSubscription->cancel_at ?? null);

        $subscription = $tenant->subscriptions()->firstOrNew([
            'stripe_id' => $subscriptionId,
        ]);

        $subscription->name = 'default';
        $subscription->stripe_status = $status;

        if (! empty($items)) {
            $firstItem = $items[0];
            if (is_array($firstItem)) {
                $subscription->stripe_price = $firstItem['price']['id'] ?? null;
                $subscription->quantity = $firstItem['quantity'] ?? 1;
            } else {
                $subscription->stripe_price = $firstItem->price->id ?? null;
                $subscription->quantity = $firstItem->quantity ?? 1;
            }
        } else {
            $subscription->stripe_price = null;
            $subscription->quantity = 1;
            Log::warning('Subscription has no items', [
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

        foreach ($items as $item) {
            try {
                $itemId = is_array($item) ? ($item['id'] ?? null) : ($item->id ?? null);
                $itemPrice = is_array($item) ? ($item['price'] ?? null) : ($item->price ?? null);
                $itemQuantity = is_array($item) ? ($item['quantity'] ?? 1) : ($item->quantity ?? 1);

                if (! $itemId || ! $itemPrice) {
                    Log::warning('Skipping subscription item - missing ID or price', [
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
                Log::error('Error syncing subscription item', [
                    'subscription_id' => $subscriptionId,
                    'error' => $e->getMessage(),
                    'item' => is_array($item) ? $item : (array) $item,
                ]);
            }
        }

        $tenant->refresh();
        $tenant->unsetRelation('subscriptions');
        $planService->forgetCurrentPlanCache($tenant);
        $planAfter = $planService->getCurrentPlan($tenant);

        app(SubscriptionBillingNotifier::class)->notifyPlanChangedAfterSync($tenant, $planBefore, $planAfter);
    }
}
