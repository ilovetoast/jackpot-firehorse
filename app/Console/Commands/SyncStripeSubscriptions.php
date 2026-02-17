<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;

class SyncStripeSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stripe:sync-subscriptions {--tenant-id= : Sync for specific tenant ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually sync subscriptions from Stripe to local database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $stripeSecret = config('services.stripe.secret');
        
        if (!$stripeSecret) {
            $this->error('Stripe secret key not configured. Set STRIPE_SECRET in .env');
            return Command::FAILURE;
        }
        
        Stripe::setApiKey($stripeSecret);

        $tenantId = $this->option('tenant-id');
        
        if ($tenantId) {
            $tenants = Tenant::where('id', $tenantId)->whereNotNull('stripe_id')->get();
        } else {
            $tenants = Tenant::whereNotNull('stripe_id')->get();
        }

        if ($tenants->isEmpty()) {
            $this->error('No tenants with Stripe IDs found.');
            return Command::FAILURE;
        }

        $this->info("Syncing subscriptions for {$tenants->count()} tenant(s)...");

        foreach ($tenants as $tenant) {
            $this->info("\nSyncing tenant: {$tenant->name} (Stripe ID: {$tenant->stripe_id})");
            
            try {
                // Get all subscriptions from Stripe
                $stripeSubscriptions = StripeSubscription::all([
                    'customer' => $tenant->stripe_id,
                    'limit' => 100,
                ]);

                $subscriptionCount = is_array($stripeSubscriptions->data) 
                    ? count($stripeSubscriptions->data) 
                    : $stripeSubscriptions->data->count();
                
                $this->info("Found {$subscriptionCount} subscription(s) in Stripe");

                $subscriptions = is_array($stripeSubscriptions->data) 
                    ? $stripeSubscriptions->data 
                    : $stripeSubscriptions->data->toArray();
                
                foreach ($subscriptions as $stripeSubscription) {
                    try {
                        // Convert to object if it's an array
                        $sub = is_array($stripeSubscription) 
                            ? (object) $stripeSubscription 
                            : $stripeSubscription;
                        
                        $this->syncSubscription($tenant, $sub);
                        $this->line("  ✓ Synced subscription: {$sub->id} ({$sub->status})");
                    } catch (\Exception $e) {
                        $subId = is_array($stripeSubscription) ? ($stripeSubscription['id'] ?? 'unknown') : $stripeSubscription->id;
                        $this->error("  ✗ Failed to sync subscription {$subId}: {$e->getMessage()}");
                    }
                }
            } catch (\Exception $e) {
                $this->error("Failed to fetch subscriptions for tenant {$tenant->name}: {$e->getMessage()}");
            }
        }

        $this->info("\nSync complete!");
        return Command::SUCCESS;
    }

    /**
     * Sync a single subscription.
     */
    protected function syncSubscription(Tenant $tenant, $stripeSubscription): void
    {
        $subscription = $tenant->subscriptions()->firstOrNew([
            'stripe_id' => $stripeSubscription->id,
        ]);

        $subscription->name = 'default';
        $subscription->stripe_status = $stripeSubscription->status;
        
        // Get price from first item
        if (!empty($stripeSubscription->items->data)) {
            $firstItem = $stripeSubscription->items->data[0];
            $subscription->stripe_price = $firstItem->price->id;
            $subscription->quantity = $firstItem->quantity ?? 1;
        } else {
            $subscription->stripe_price = null;
            $subscription->quantity = 1;
        }

        $subscription->trial_ends_at = $stripeSubscription->trial_end 
            ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->trial_end) 
            : null;
        $subscription->ends_at = $stripeSubscription->cancel_at 
            ? \Carbon\Carbon::createFromTimestamp($stripeSubscription->cancel_at) 
            : null;

        $subscription->save();

        // Sync subscription items
        foreach ($stripeSubscription->items->data as $item) {
            $subscriptionItem = $subscription->items()->firstOrNew([
                'stripe_id' => $item->id,
            ]);

            $subscriptionItem->stripe_product = $item->price->product;
            $subscriptionItem->stripe_price = $item->price->id;
            $subscriptionItem->quantity = $item->quantity ?? 1;
            $subscriptionItem->save();
        }
    }
}
