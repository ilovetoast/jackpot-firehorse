<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Billing\StripeCustomerVerifier;
use App\Services\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verify tenants.stripe_id against the current Stripe account (STRIPE_SECRET) and optionally repair ghost IDs.
 *
 * Safe default: dry-run. Use --force to clear a stale ID and create a new Stripe customer via Cashier.
 * Refuses when a local subscription row looks active/trialing/past_due unless --force (ops escape hatch).
 */
class BillingRepairStripeCustomerCommand extends Command
{
    protected $signature = 'billing:repair-stripe-customer
        {tenant_id : The tenant (company) primary key}
        {--dry-run : Show actions without writing to the database or Stripe}
        {--force : Allow repair even when an active-looking subscription exists (dangerous)}
        {--create-missing : When stripe_id is empty, create a Stripe customer now (otherwise only checkout does)}';

    protected $description = 'Check tenant Stripe customer ID against Stripe; optionally clear stale ID and recreate customer';

    public function handle(StripeCustomerVerifier $verifier, BillingService $billingService): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            $this->error("Tenant {$tenantId} not found.");

            return self::FAILURE;
        }

        if (empty(config('services.stripe.secret'))) {
            $this->error('STRIPE_SECRET / services.stripe.secret is not configured.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->info("Tenant #{$tenant->id} ({$tenant->name})");
        $this->line('Environment: '.config('app.env'));
        $this->line('stripe_id: '.($tenant->stripe_id ?? '(null)'));

        if (! $tenant->stripe_id) {
            $this->warn('No stripe_id on tenant — checkout normally creates the Stripe customer on first paid upgrade.');
            if ($dry) {
                return self::SUCCESS;
            }
            if (! (bool) $this->option('create-missing')) {
                $this->comment('Pass --create-missing to create a Stripe customer immediately (optional).');

                return self::SUCCESS;
            }
            $billingService->ensureValidStripeCustomer($tenant);
            $this->info('Created Stripe customer: '.$tenant->fresh()->stripe_id);

            return self::SUCCESS;
        }

        try {
            $exists = $verifier->customerExistsInStripeAccount($tenant->stripe_id);
        } catch (\Throwable $e) {
            $this->error('Stripe API error: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($exists) {
            $this->info('Stripe customer exists for this key — no repair needed.');

            return self::SUCCESS;
        }

        $this->warn('Customer missing in Stripe for this account/mode (stale or wrong environment).');

        $activeLike = DB::table('subscriptions')
            ->where('tenant_id', $tenant->id)
            ->whereIn('stripe_status', ['active', 'trialing', 'past_due'])
            ->exists();

        if ($activeLike && ! $force) {
            $this->error('Refusing to clear stripe_id: subscription rows look active. Reconcile Stripe first or pass --force (dangerous).');

            return self::FAILURE;
        }

        if ($dry) {
            $this->warn('[dry-run] Would clear stripe_id / payment method fields and create a new Stripe customer.');

            return self::SUCCESS;
        }

        $old = $tenant->stripe_id;
        $tenant->stripe_id = null;
        $tenant->pm_type = null;
        $tenant->pm_last_four = null;
        $tenant->saveQuietly();
        $tenant->refresh();

        $billingService->ensureValidStripeCustomer($tenant);
        $this->info("Replaced {$old} → ".$tenant->fresh()->stripe_id);

        return self::SUCCESS;
    }
}
