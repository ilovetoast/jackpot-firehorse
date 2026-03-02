<?php

namespace App\BrandDNA\Builder;

/**
 * Brand Guidelines Builder — step configuration.
 * Single source of truth for step order, allowed paths, and publish requirements.
 */
class BrandGuidelinesBuilderSteps
{
    public const STEP_BACKGROUND = 'background';
    public const STEP_POSITIONING = 'positioning';
    public const STEP_ARCHETYPE = 'archetype';
    public const STEP_PURPOSE_PROMISE = 'purpose_promise';
    public const STEP_EXPRESSION = 'expression';
    public const STEP_STANDARDS = 'standards';

    /**
     * All step keys in order.
     * Order: Background → Archetype → Purpose → Expression → Positioning → Standards
     */
    public static function stepKeys(): array
    {
        return [
            self::STEP_BACKGROUND,
            self::STEP_ARCHETYPE,
            self::STEP_PURPOSE_PROMISE,
            self::STEP_EXPRESSION,
            self::STEP_POSITIONING,
            self::STEP_STANDARDS,
        ];
    }

    /**
     * Full step configuration.
     *
     * @return array<int, array{key: string, title: string, description: string, skippable: bool, allowed_paths: array<string>, required_on_publish_paths: array<string>}>
     */
    public static function steps(): array
    {
        return [
            [
                'key' => self::STEP_BACKGROUND,
                'title' => 'Background',
                'description' => 'Website, social links, and communication examples',
                'skippable' => false,
                'allowed_paths' => ['sources'],
                'required_on_publish_paths' => [],
            ],
            [
                'key' => self::STEP_ARCHETYPE,
                'title' => 'Archetype',
                'description' => 'Primary archetype and candidates',
                'skippable' => false,
                'allowed_paths' => ['personality'],
                'required_on_publish_paths' => [],
            ],
            [
                'key' => self::STEP_PURPOSE_PROMISE,
                'title' => 'Purpose',
                'description' => 'Why and What statements',
                'skippable' => true,
                'allowed_paths' => ['identity'],
                'required_on_publish_paths' => [],
            ],
            [
                'key' => self::STEP_EXPRESSION,
                'title' => 'Brand Expression',
                'description' => 'Brand Look and Brand Voice',
                'skippable' => true,
                'allowed_paths' => ['personality'],
                'required_on_publish_paths' => [],
            ],
            [
                'key' => self::STEP_POSITIONING,
                'title' => 'Positioning',
                'description' => 'Industry, audience, beliefs, values, tagline',
                'skippable' => true,
                'allowed_paths' => ['identity'],
                'required_on_publish_paths' => [],
            ],
            [
                'key' => self::STEP_STANDARDS,
                'title' => 'Standards',
                'description' => 'Colors, typography, photography references',
                'skippable' => true,
                'allowed_paths' => ['typography', 'scoring_rules', 'visual', 'brand_colors'],
                'required_on_publish_paths' => [],
            ],
        ];
    }

    /**
     * Get step config by key.
     */
    public static function stepByKey(string $key): ?array
    {
        foreach (self::steps() as $step) {
            if ($step['key'] === $key) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Allowed top-level paths for a step (for patch endpoint).
     *
     * @return array<string>
     */
    public static function allowedPathsForStep(string $stepKey): array
    {
        $step = self::stepByKey($stepKey);

        return $step['allowed_paths'] ?? [];
    }

    /**
     * Check if step_key is valid.
     */
    public static function isValidStepKey(string $stepKey): bool
    {
        return self::stepByKey($stepKey) !== null;
    }
}
