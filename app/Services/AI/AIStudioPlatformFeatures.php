<?php

namespace App\Services\AI;

use App\Models\AIStudioPlatformFeatureToggle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide Studio feature flags (admin toggles + config defaults).
 */
final class AIStudioPlatformFeatures
{
    public const FEATURE_STUDIO_COMPOSITION_ANIMATION = 'studio_composition_animation';

    public const FEATURE_STUDIO_LAYER_EXTRACTION_AI = 'studio_layer_extraction_ai';

    public const FEATURE_STUDIO_LAYER_BACKGROUND_FILL = 'studio_layer_background_fill';

    /** @var array<string, array<string, bool>> */
    private static array $resolvedMapCache = [];

    /**
     * @return array<string, bool>
     */
    public function effectiveMap(?string $environment = null): array
    {
        return $this->resolvedMap($environment ?? app()->environment());
    }

    public function isStudioCompositionAnimationEnabled(?string $environment = null): bool
    {
        return $this->isEnabled(self::FEATURE_STUDIO_COMPOSITION_ANIMATION, $environment);
    }

    public function isStudioLayerExtractionAiEnabled(?string $environment = null): bool
    {
        return $this->isEnabled(self::FEATURE_STUDIO_LAYER_EXTRACTION_AI, $environment);
    }

    public function isStudioLayerBackgroundFillEnabled(?string $environment = null): bool
    {
        return $this->isEnabled(self::FEATURE_STUDIO_LAYER_BACKGROUND_FILL, $environment);
    }

    public function isEnabled(string $featureKey, ?string $environment = null): bool
    {
        $environment = $environment ?? app()->environment();
        $map = $this->resolvedMap($environment);

        return $map[$featureKey] ?? (bool) (config("ai_studio_platform_features.features.{$featureKey}.default_enabled") ?? true);
    }

    /**
     * @return array<int, array{key: string, label: string, description: string, enabled: bool, default_enabled: bool}>
     */
    public function adminPayload(?string $environment = null): array
    {
        $environment = $environment ?? app()->environment();
        $defs = config('ai_studio_platform_features.features', []);
        $map = $this->resolvedMap($environment);
        $rows = [];
        foreach ($defs as $key => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $k = (string) $key;
            $defaultEnabled = (bool) ($meta['default_enabled'] ?? true);
            $rows[] = [
                'key' => $k,
                'label' => (string) ($meta['label'] ?? $k),
                'description' => (string) ($meta['description'] ?? ''),
                'default_enabled' => $defaultEnabled,
                'enabled' => $map[$k] ?? $defaultEnabled,
            ];
        }

        return $rows;
    }

    public function setEnabled(string $featureKey, bool $enabled, User $user, ?string $environment = null): AIStudioPlatformFeatureToggle
    {
        $environment = $environment ?? app()->environment();

        return DB::transaction(function () use ($featureKey, $enabled, $user, $environment) {
            $row = AIStudioPlatformFeatureToggle::query()->firstOrNew([
                'feature_key' => $featureKey,
                'environment' => $environment,
            ]);
            $row->enabled = $enabled;
            $row->updated_by_user_id = $user->id;
            $row->save();
            unset(self::$resolvedMapCache[$environment]);

            return $row;
        });
    }

    /**
     * @return array<string, bool>
     */
    private function resolvedMap(string $environment): array
    {
        if (isset(self::$resolvedMapCache[$environment])) {
            return self::$resolvedMapCache[$environment];
        }

        $definitions = config('ai_studio_platform_features.features', []);
        if (! is_array($definitions) || $definitions === []) {
            self::$resolvedMapCache[$environment] = [];

            return [];
        }

        $keys = array_keys($definitions);
        $dbRows = AIStudioPlatformFeatureToggle::query()
            ->whereIn('feature_key', $keys)
            ->where(function ($q) use ($environment) {
                $q->where('environment', $environment)
                    ->orWhere('environment', '');
            })
            ->get()
            ->groupBy(fn (AIStudioPlatformFeatureToggle $r) => $r->feature_key);

        $out = [];
        foreach ($keys as $key) {
            $default = (bool) ($definitions[$key]['default_enabled'] ?? true);
            $group = $dbRows->get($key);
            if ($group === null || $group->isEmpty()) {
                $out[$key] = $default;
                continue;
            }
            $exact = $group->firstWhere('environment', $environment);
            $fallback = $group->firstWhere('environment', '');
            $chosen = $exact ?? $fallback;
            $out[$key] = $chosen !== null ? (bool) $chosen->enabled : $default;
        }

        self::$resolvedMapCache[$environment] = $out;

        return $out;
    }
}
