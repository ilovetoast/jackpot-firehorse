<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Subdomain Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains all configuration options for the subdomain
    | tenant system. You can enable/disable subdomains and configure
    | various aspects of how they work.
    |
    */

    'enabled' => env('SUBDOMAIN_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Main Domain
    |--------------------------------------------------------------------------
    |
    | This is automatically derived from APP_URL, but you can override it here
    | if needed. This is used to determine which domain is the main app and
    | which are tenant subdomains.
    |
    */
    
    'main_domain' => parse_url(config('app.url'), PHP_URL_HOST),

    /*
    |--------------------------------------------------------------------------
    | Reserved Subdomains
    |--------------------------------------------------------------------------
    |
    | These subdomain slugs are reserved and cannot be used by tenants.
    | Add any subdomains you want to reserve for system use.
    |
    */
    
    'reserved_slugs' => [
        // Infrastructure
        'www', 'api', 'app', 'admin', 'root', 'system', 'server', 'host', 'localhost',
        
        // Development & Testing
        'dev', 'test', 'staging', 'beta', 'demo',
        
        // Communication & Support
        'mail', 'ftp', 'support', 'help', 'docs', 'blog',
        
        // Media & Assets  
        'cdn', 'assets', 'static', 'media', 'images', 'files', 'uploads',
        
        // Authentication & User Management
        'login', 'signin', 'signup', 'register', 'auth', 'oauth', 'sso', 
        'profile', 'settings', 'account', 'accounts', 'dashboard', 'portal',
        
        // E-commerce & Billing
        'shop', 'store', 'billing', 'payment', 'payments', 'checkout', 'order', 
        'orders', 'invoice', 'invoices', 'subscription', 'subscriptions', 
        'plan', 'plans',
        
        // Monitoring & Status
        'status', 'monitor', 'health',
        
        // Brand/App Specific (add your app name here)
        'jackpot',
    ],
];