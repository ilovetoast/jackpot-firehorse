<?php

declare(strict_types=1);

namespace App\Services\Billing;

use InvalidArgumentException;

/**
 * Resolves Stripe Price IDs from {@see config/billing_stripe.php} for plans and add-ons.
 * Intended for checkout, subscription updates, and webhook normalization.
 */
final class StripePriceMap
{
    /** @var array<string, string> */
    private const AI_CREDITS_INTERNAL_TO_CONFIG = [
        'credits_500' => 'ai_credits_500',
        'credits_2000' => 'ai_credits_2000',
        'credits_10000' => 'ai_credits_10000',
    ];

    public function priceIdForPlan(string $planKey): ?string
    {
        return self::normalize(config("billing_stripe.stripe_prices.plans.{$planKey}"));
    }

    /**
     * Add-on keys match {@see config('billing_stripe.stripe_prices.addons')} (e.g. storage_100gb, ai_credits_500).
     */
    public function priceIdForAddon(string $addonKey): ?string
    {
        return self::normalize(config("billing_stripe.stripe_prices.addons.{$addonKey}"));
    }

    /**
     * Maps config/ai_credits.php pack ids (credits_500, …) to Stripe Price IDs.
     */
    public function priceIdForAiCreditsPackId(string $internalPackId): ?string
    {
        $addonKey = self::AI_CREDITS_INTERNAL_TO_CONFIG[$internalPackId] ?? null;
        if ($addonKey === null) {
            throw new InvalidArgumentException("Unknown AI credits pack id: {$internalPackId}");
        }

        return $this->priceIdForAddon($addonKey);
    }

    /**
     * @return array{kind: 'plan'|'addon', key: string}|null
     */
    public function resolveKeyForPriceId(string $priceId): ?array
    {
        $normalized = trim($priceId);
        if ($normalized === '') {
            return null;
        }

        foreach (config('billing_stripe.stripe_prices.plans', []) as $key => $id) {
            if (self::normalize($id) === $normalized) {
                return ['kind' => 'plan', 'key' => (string) $key];
            }
        }

        foreach (config('billing_stripe.stripe_prices.addons', []) as $key => $id) {
            if (self::normalize($id) === $normalized) {
                return ['kind' => 'addon', 'key' => (string) $key];
            }
        }

        return null;
    }

    /**
     * @throws InvalidArgumentException when two keys share the same non-empty price id
     */
    public static function assertUniquePriceIds(): void
    {
        $prices = config('billing_stripe.stripe_prices', []);
        $seen = [];

        foreach (['plans', 'addons'] as $group) {
            foreach (($prices[$group] ?? []) as $key => $rawId) {
                $id = self::normalize($rawId);
                if ($id === null) {
                    continue;
                }
                if (isset($seen[$id])) {
                    throw new InvalidArgumentException(
                        "Duplicate Stripe price ID {$id} for {$seen[$id]} and {$group}.{$key}"
                    );
                }
                $seen[$id] = "{$group}.{$key}";
            }
        }
    }

    private static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
