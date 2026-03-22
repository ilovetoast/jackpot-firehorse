<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Staging-only: single verified From address with per-tenant display name; optional Reply-To from tenant email.
 */
final class TenantMailBranding
{
    /** @var array{address: string, name: string}|null */
    private static ?array $capturedDefaults = null;

    public static function enabled(): bool
    {
        $override = config('mail.tenant_branding.enabled');

        if ($override !== null) {
            return (bool) $override;
        }

        return app()->environment('staging');
    }

    public static function apply(?Tenant $tenant): void
    {
        if (! self::enabled()) {
            return;
        }

        self::captureDefaultsOnce();

        if ($tenant) {
            config([
                'mail.from.address' => config('mail.tenant_branding.from_address'),
                'mail.from.name' => $tenant->name,
            ]);
        } else {
            self::restoreDefaults();
        }
    }

    public static function reset(): void
    {
        self::restoreDefaults();
        self::$capturedDefaults = null;
    }

    private static function captureDefaultsOnce(): void
    {
        if (self::$capturedDefaults !== null) {
            return;
        }

        self::$capturedDefaults = [
            'address' => (string) config('mail.from.address'),
            'name' => (string) config('mail.from.name'),
        ];
    }

    private static function restoreDefaults(): void
    {
        if (self::$capturedDefaults === null) {
            return;
        }

        config([
            'mail.from.address' => self::$capturedDefaults['address'],
            'mail.from.name' => self::$capturedDefaults['name'],
        ]);
    }
}
