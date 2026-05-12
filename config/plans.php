<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Subscription Plans
    |--------------------------------------------------------------------------
    |
    | Define subscription plans with their Stripe price IDs and feature limits.
    | Paid plan price IDs are centralized in config/billing_stripe.php (env STRIPE_PRICE_*_MONTHLY).
    | Plans define feature limits, not access logic.
    |
    | AI usage is tracked per-feature for analytics but enforced via a unified
    | credit pool (max_ai_credits_per_month). Credit weights live in
    | config/ai_credits.php.
    |
    | Note: max_categories is legacy; custom category count is not plan-gated.
    | Per-brand visible category caps use config/categories.php (asset + deliverable).
    |
    | Stable plan keys: free, starter, pro, business, enterprise.
    | "premium" is a legacy alias — see PlanService::resolveCurrentPlan().
    |
    */

    'free' => [
        'name' => 'Free',
        'stripe_price_id' => env('STRIPE_PRICE_FREE', 'price_free'),
        'selectable' => true,
        'limits' => [
            'max_brands' => 1,
            'max_categories' => 0,
            'max_storage_mb' => 1024, // 1 GB
            'max_upload_size_mb' => 10,
            'max_users' => 2,
            'max_downloads_per_month' => 25,
            'max_download_assets' => 25,
            'max_download_zip_mb' => 250,
            'max_custom_metadata_fields' => 0,
            'max_tags_per_asset' => 4,
            'max_ai_credits_per_month' => 75,
        ],
        'features' => [
            'basic_asset_types',
        ],
        'sso_enabled' => false,
        'creator_module_included' => false,
        'creator_module_included_seats' => 0,
        'requires_email_verification_for_uploads' => true,
        'addons_available' => [],
        'approval_features' => [
            'approvals.enabled' => false,
            'notifications.enabled' => false,
            'approval_summaries.enabled' => false,
        ],
        'public_collections_enabled' => false,
        'brand_portal' => [
            'customization' => false,
            'public_access' => false,
            'sharing' => false,
            'agency_templates' => false,
        ],
        'brand_guidelines' => [
            'customization' => false,
        ],
        'download_features' => [
            'download_links_limited' => true,
            'download_links_limit' => 25,
            'custom_download_permissions' => false,
            'share_downloads_with_permissions' => false,
        ],
        'download_management' => [
            'extend_expiration' => false,
            'revoke' => false,
            'restrict_access_brand' => false,
            'restrict_access_company' => false,
            'restrict_access_users' => false,
            'non_expiring' => false,
            'regenerate' => false,
            'rename' => false,
            'password_protection' => false,
            'branding' => false,
            'require_landing_page' => false,
            'max_expiration_days' => 30,
        ],
        'versions_enabled' => false,
        'max_versions_per_asset' => 1,
        'notes' => [
            '1 GB storage, 10 MB max upload',
            '75 AI credits per month',
            '25 downloads per month',
            'Owner email verification required before uploads',
            'No add-ons — upgrade to unlock',
        ],
    ],

    'starter' => [
        'name' => 'Starter',
        'stripe_price_id' => config('billing_stripe.stripe_prices.plans.starter'),
        'fallback_monthly_price' => 59.00,
        'selectable' => true,
        'limits' => [
            'max_brands' => 1,
            'max_categories' => 0,
            'max_storage_mb' => 51200, // 50 GB
            'max_upload_size_mb' => 50,
            'max_users' => 5,
            'max_downloads_per_month' => 200,
            'max_download_assets' => 100,
            'max_download_zip_mb' => 1024, // 1 GB
            'max_custom_metadata_fields' => 5,
            'max_tags_per_asset' => 8,
            'max_ai_credits_per_month' => 300,
        ],
        'features' => [
            'all_asset_types',
        ],
        'sso_enabled' => false,
        'creator_module_included' => false,
        'creator_module_included_seats' => 0,
        'requires_email_verification_for_uploads' => false,
        'addons_available' => [
            'storage' => ['storage_100gb'],
            'ai_credits' => ['credits_500'],
        ],
        'approval_features' => [
            'approvals.enabled' => false,
            'notifications.enabled' => true,
            'approval_summaries.enabled' => false,
        ],
        'public_collections_enabled' => false,
        'brand_portal' => [
            'customization' => false,
            'public_access' => false,
            'sharing' => false,
            'agency_templates' => false,
        ],
        'brand_guidelines' => [
            'customization' => false,
        ],
        'download_features' => [
            'download_links_limited' => true,
            'download_links_limit' => 200,
            'custom_download_permissions' => true,
            'share_downloads_with_permissions' => true,
        ],
        'download_management' => [
            'extend_expiration' => false,
            'revoke' => false,
            'restrict_access_brand' => false,
            'restrict_access_company' => false,
            'restrict_access_users' => false,
            'non_expiring' => false,
            'regenerate' => false,
            'rename' => false,
            'password_protection' => false,
            'branding' => false,
            'require_landing_page' => false,
            'max_expiration_days' => 30,
        ],
        'versions_enabled' => true,
        'max_versions_per_asset' => 5,
        'notes' => [
            '50 GB storage, 50 MB max upload',
            '300 AI credits per month',
            '200 downloads per month',
            'Versioning enabled (max 5 per asset)',
            'Basic sharing with custom permissions',
        ],
    ],

    'pro' => [
        'name' => 'Pro',
        'stripe_price_id' => config('billing_stripe.stripe_prices.plans.pro'),
        'fallback_monthly_price' => 199.00,
        'selectable' => true,
        'limits' => [
            'max_brands' => 3,
            'max_categories' => 0,
            'max_private_categories' => 5,
            'max_storage_mb' => 256000, // 250 GB
            'max_upload_size_mb' => 999999,
            'max_users' => 20,
            'max_downloads_per_month' => 1000,
            'max_download_assets' => 500,
            'max_download_zip_mb' => 2048, // 2 GB
            'max_custom_metadata_fields' => 20,
            'max_tags_per_asset' => 13,
            'max_ai_credits_per_month' => 1500,
        ],
        'features' => [
            'all_asset_types',
            'advanced_features',
            'access_to_more_roles',
            'edit_system_categories',
        ],
        'sso_enabled' => false,
        'creator_module_included' => false,
        'creator_module_included_seats' => 0,
        'requires_email_verification_for_uploads' => false,
        'addons_available' => [
            'storage' => ['storage_100gb', 'storage_500gb', 'storage_1tb'],
            'ai_credits' => ['credits_500', 'credits_2000'],
            'creator_module' => true,
        ],
        'versions_enabled' => true,
        'max_versions_per_asset' => 25,
        'approval_features' => [
            'approvals.enabled' => true,
            'notifications.enabled' => true,
            'approval_summaries.enabled' => true,
        ],
        'public_collections_enabled' => true,
        /** Guest-facing ZIP from public collection page when the collection allows downloads (same as Business+). */
        'public_collection_downloads_enabled' => true,
        'brand_portal' => [
            'customization' => true,
            'public_access' => false,
            'sharing' => false,
            'agency_templates' => false,
        ],
        'brand_guidelines' => [
            'customization' => true,
        ],
        'download_features' => [
            'download_links_limited' => true,
            'download_links_limit' => 1000,
            'custom_download_permissions' => true,
            'share_downloads_with_permissions' => true,
        ],
        'download_management' => [
            'extend_expiration' => true,
            'revoke' => true,
            'restrict_access_brand' => true,
            'restrict_access_company' => true,
            'restrict_access_users' => true,
            'non_expiring' => false,
            'regenerate' => false,
            'rename' => false,
            'password_protection' => false,
            'branding' => true,
            'require_landing_page' => true,
            'max_expiration_days' => 90,
        ],
        'notes' => [
            '250 GB storage, unlimited upload size',
            '1,500 AI credits per month',
            '1,000 downloads per month',
            'Full approvals and advanced permissions',
            'Public collection share links (optional password; guest ZIP when collection allows downloads)',
            'Creator Module available as add-on ($99/mo)',
        ],
    ],

    'business' => [
        'name' => 'Business',
        'stripe_price_id' => config('billing_stripe.stripe_prices.plans.business'),
        'fallback_monthly_price' => 599.00,
        'selectable' => true,
        'limits' => [
            'max_brands' => 10,
            'max_categories' => 0,
            'max_private_categories' => 10,
            'max_storage_mb' => 1048576, // 1 TB (1024 * 1024 MB)
            'max_upload_size_mb' => 999999,
            'max_users' => 75,
            'max_downloads_per_month' => 999999,
            'max_download_assets' => 2000,
            'max_download_zip_mb' => 5120, // 5 GB
            'max_custom_metadata_fields' => 100,
            'max_tags_per_asset' => 18,
            'max_ai_credits_per_month' => 6000,
        ],
        'features' => [
            'all_asset_types',
            'advanced_features',
            'custom_integrations',
            'access_to_more_roles',
            'edit_system_categories',
        ],
        'sso_enabled' => true,
        'creator_module_included' => true,
        'creator_module_included_seats' => 50,
        'requires_email_verification_for_uploads' => false,
        'addons_available' => [
            'storage' => ['storage_500gb', 'storage_1tb'],
            'ai_credits' => ['credits_2000', 'credits_10000'],
            'creator_seats' => ['creator_seats_25', 'creator_seats_100'],
        ],
        'versions_enabled' => true,
        'max_versions_per_asset' => 250,
        'approval_features' => [
            'approvals.enabled' => true,
            'notifications.enabled' => true,
            'approval_summaries.enabled' => true,
        ],
        'public_collections_enabled' => true,
        'public_collection_downloads_enabled' => true,
        'brand_portal' => [
            'customization' => true,
            'public_access' => true,
            'sharing' => true,
            'agency_templates' => false,
        ],
        'brand_guidelines' => [
            'customization' => true,
        ],
        'download_features' => [
            'download_links_limited' => false,
            'download_links_limit' => 999999,
            'custom_download_permissions' => true,
            'share_downloads_with_permissions' => true,
        ],
        'download_management' => [
            'extend_expiration' => true,
            'revoke' => true,
            'restrict_access_brand' => true,
            'restrict_access_company' => true,
            'restrict_access_users' => true,
            'non_expiring' => true,
            'regenerate' => true,
            'rename' => true,
            'password_protection' => true,
            'branding' => true,
            'require_landing_page' => true,
            'max_expiration_days' => 365,
        ],
        'download_policy' => [
            'disable_single_asset_downloads' => false,
            'require_password_for_public' => false,
            'force_expiration_days' => null,
            'disallow_non_expiring' => false,
        ],
        'notes' => [
            '1 TB storage, unlimited upload size',
            '6,000 AI credits per month',
            'SSO enabled',
            'Creator Module included (50 seats)',
            'Full download management with policy controls',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy alias: "premium" maps to "business"
    |--------------------------------------------------------------------------
    |
    | Existing customers on the old Premium ($499) Stripe price are
    | grandfathered. PlanService::resolveCurrentPlan() maps the legacy
    | STRIPE_PRICE_ENTERPRISE env var to the 'business' plan key.
    | This block keeps config('plans.premium') lookups working during
    | migration. Remove after all legacy customers are migrated.
    |
    */
    'premium' => [
        'name' => 'Business (Legacy)',
        'stripe_price_id' => env('STRIPE_PRICE_ENTERPRISE', 'price_1SlzxCBF7ZSvskYAigcdiKKj'),
        'fallback_monthly_price' => 499.00,
        'selectable' => false,
        'legacy_alias_for' => 'business',
        'limits' => [
            'max_brands' => 10,
            'max_categories' => 0,
            'max_private_categories' => 10,
            'max_storage_mb' => 1048576, // 1 TB
            'max_upload_size_mb' => 999999,
            'max_users' => 75,
            'max_downloads_per_month' => 999999,
            'max_download_assets' => 2000,
            'max_download_zip_mb' => 5120,
            'max_custom_metadata_fields' => 100,
            'max_tags_per_asset' => 18,
            'max_ai_credits_per_month' => 6000,
        ],
        'features' => [
            'all_asset_types',
            'advanced_features',
            'custom_integrations',
            'access_to_more_roles',
            'edit_system_categories',
        ],
        'sso_enabled' => true,
        'creator_module_included' => true,
        'creator_module_included_seats' => 50,
        'requires_email_verification_for_uploads' => false,
        'addons_available' => [
            'storage' => ['storage_500gb', 'storage_1tb'],
            'ai_credits' => ['credits_2000', 'credits_10000'],
            'creator_seats' => ['creator_seats_25', 'creator_seats_100'],
        ],
        'versions_enabled' => true,
        'max_versions_per_asset' => 250,
        'approval_features' => [
            'approvals.enabled' => true,
            'notifications.enabled' => true,
            'approval_summaries.enabled' => true,
        ],
        'public_collections_enabled' => true,
        'public_collection_downloads_enabled' => true,
        'brand_portal' => [
            'customization' => true,
            'public_access' => true,
            'sharing' => true,
            'agency_templates' => false,
        ],
        'brand_guidelines' => [
            'customization' => true,
        ],
        'download_features' => [
            'download_links_limited' => false,
            'download_links_limit' => 999999,
            'custom_download_permissions' => true,
            'share_downloads_with_permissions' => true,
        ],
        'download_management' => [
            'extend_expiration' => true,
            'revoke' => true,
            'restrict_access_brand' => true,
            'restrict_access_company' => true,
            'restrict_access_users' => true,
            'non_expiring' => true,
            'regenerate' => true,
            'rename' => true,
            'password_protection' => true,
            'branding' => true,
            'require_landing_page' => true,
            'max_expiration_days' => 365,
        ],
        'download_policy' => [
            'disable_single_asset_downloads' => false,
            'require_password_for_public' => false,
            'force_expiration_days' => null,
            'disallow_non_expiring' => false,
        ],
        'notes' => [
            'Legacy Premium plan — grandfathered at $499/mo',
            'Same features as Business plan',
        ],
    ],

    'enterprise' => [
        'name' => 'Enterprise',
        'stripe_price_id' => null,
        'selectable' => false,
        'requires_contact' => true,
        'limits' => [
            'max_brands' => 999,
            'max_categories' => 999,
            'max_private_categories' => 999,
            'max_storage_mb' => 20971520, // 20 TB
            'max_upload_size_mb' => 999999,
            'max_users' => 999,
            'max_downloads_per_month' => 999999,
            'max_download_assets' => 5000,
            'max_download_zip_mb' => 10240,
            'max_custom_metadata_fields' => 500,
            'max_tags_per_asset' => 53,
            'max_ai_credits_per_month' => 0, // 0 = unlimited
        ],
        'features' => [
            'all_asset_types',
            'advanced_features',
            'custom_integrations',
            'access_to_more_roles',
            'edit_system_categories',
        ],
        'sso_enabled' => true,
        'creator_module_included' => true,
        'creator_module_included_seats' => 200,
        'requires_email_verification_for_uploads' => false,
        'addons_available' => [],
        'versions_enabled' => true,
        'max_versions_per_asset' => 500,
        'approval_features' => [
            'approvals.enabled' => true,
            'notifications.enabled' => true,
            'approval_summaries.enabled' => true,
        ],
        'public_collections_enabled' => true,
        'public_collection_downloads_enabled' => true,
        'brand_portal' => [
            'customization' => true,
            'public_access' => true,
            'sharing' => true,
            'agency_templates' => true,
        ],
        'brand_guidelines' => [
            'customization' => true,
        ],
        'download_features' => [
            'download_links_limited' => false,
            'download_links_limit' => 999999,
            'custom_download_permissions' => true,
            'share_downloads_with_permissions' => true,
        ],
        'download_management' => [
            'extend_expiration' => true,
            'revoke' => true,
            'restrict_access_brand' => true,
            'restrict_access_company' => true,
            'restrict_access_users' => true,
            'non_expiring' => true,
            'regenerate' => true,
            'rename' => true,
            'password_protection' => true,
            'branding' => true,
            'require_landing_page' => true,
            'max_expiration_days' => 365,
        ],
        'download_policy' => [
            'disable_single_asset_downloads' => false,
            'require_password_for_public' => false,
            'force_expiration_days' => null,
            'disallow_non_expiring' => false,
        ],
        'notes' => [
            'Sales-only. Contact for pricing.',
            'Dedicated infrastructure.',
        ],
    ],
];
