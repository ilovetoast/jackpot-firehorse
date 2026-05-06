<?php

declare(strict_types=1);

return [
    /**
     * Default lifetime for a disposable demo workspace when sales tooling creates one (future phase).
     */
    'default_expiration_days' => (int) env('DEMO_DEFAULT_EXPIRATION_DAYS', 7),

    /**
     * Allowed demo lifetimes (days) for validation in future admin/sales flows.
     *
     * @var list<int>
     */
    'allowed_expiration_days' => [7, 14],

    /**
     * Plan key applied to demo instances for limits (see config/plans.php keys: starter, pro, business, …).
     */
    'default_plan_key' => env('DEMO_DEFAULT_PLAN_KEY', 'pro'),

    /**
     * When true, scheduled jobs may delete or reset expired demo tenants (future cleanup phase).
     */
    'cleanup_enabled' => (bool) env('DEMO_CLEANUP_ENABLED', false),

    /**
     * When true, allow cloning demo templates into new instances (future phase).
     */
    'cloning_enabled' => (bool) env('DEMO_CLONING_ENABLED', false),
];
