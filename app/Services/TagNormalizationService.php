<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tag Normalization Service
 *
 * Phase J.2.1: Deterministic tag normalization ensuring all tags (manual or AI-generated)
 * resolve to a single canonical form before acceptance or application.
 *
 * Rules:
 * - Lowercases tags
 * - Trims whitespace
 * - Removes punctuation (except hyphens in middle)
 * - Singularizes nouns (deterministic rules only)
 * - Enforces max length (64 chars to match asset_tag_candidates.tag column)
 * - Rejects empty or invalid results
 * - Applies tenant-scoped synonym resolution
 * - Respects tenant-level block lists
 *
 * This service is pure and repeatable - same input always produces same output.
 */
class TagNormalizationService
{
    /**
     * Maximum tag length (matches database column constraint)
     */
    public const MAX_TAG_LENGTH = 64;

    /**
     * Minimum tag length
     */
    public const MIN_TAG_LENGTH = 2;

    /**
     * Cache TTL for tenant-specific rules (1 hour)
     */
    public const CACHE_TTL = 3600;

    /**
     * Normalize a raw tag into its canonical form.
     *
     * @param string $rawTag The input tag to normalize
     * @param Tenant $tenant The tenant for scoped rules
     * @return string|null The canonical tag, or null if invalid/blocked
     */
    public function normalize(string $rawTag, Tenant $tenant): ?string
    {
        // Step 1: Basic normalization
        $normalized = $this->performBasicNormalization($rawTag);
        
        if (!$this->isValidTag($normalized)) {
            return null;
        }

        // Step 2: Apply synonym resolution (tenant-scoped)
        $normalized = $this->resolveSynonym($normalized, $tenant);
        
        // Step 3: Check block list (tenant-scoped)
        if ($this->isBlocked($normalized, $tenant)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Perform basic tag normalization (deterministic).
     *
     * @param string $tag Raw input tag
     * @return string Normalized tag
     */
    protected function performBasicNormalization(string $tag): string
    {
        // Step 1: Trim whitespace
        $tag = trim($tag);

        // Step 2: Lowercase
        $tag = mb_strtolower($tag, 'UTF-8');

        // Step 3: Remove/replace punctuation
        // Keep hyphens that are not at start/end
        $tag = preg_replace('/[^\w\s\-]/u', '', $tag);
        $tag = preg_replace('/^-+|-+$/', '', $tag); // Remove leading/trailing hyphens
        $tag = preg_replace('/\s+/', '-', $tag); // Replace spaces with hyphens
        $tag = preg_replace('/-+/', '-', $tag); // Collapse multiple hyphens

        // Step 4: Singularize (deterministic rules only)
        $tag = $this->singularize($tag);

        // Step 5: Enforce max length
        if (mb_strlen($tag, 'UTF-8') > self::MAX_TAG_LENGTH) {
            $tag = mb_substr($tag, 0, self::MAX_TAG_LENGTH, 'UTF-8');
            // Remove trailing partial words after truncation
            $tag = preg_replace('/-[^-]*$/', '', $tag);
        }

        return trim($tag, '-'); // Final cleanup of edge hyphens
    }

    /**
     * Singularize a tag using deterministic rules only.
     *
     * This uses simple, predictable rules rather than complex linguistic analysis
     * to ensure the same input always produces the same output.
     *
     * @param string $tag The tag to singularize
     * @return string The singularized tag
     */
    protected function singularize(string $tag): string
    {
        // Simple deterministic singularization rules
        $rules = [
            '/ies$/' => 'y',     // categories -> category
            '/ves$/' => 'f',     // wolves -> wolf
            '/ses$/' => 's',     // classes -> class
            '/xes$/' => 'x',     // boxes -> box
            '/zes$/' => 'z',     // fizzes -> fizz
            '/ches$/' => 'ch',   // matches -> match
            '/shes$/' => 'sh',   // wishes -> wish
            '/men$/' => 'man',   // women -> woman
            '/een$/' => 'een',   // thirteen -> thirteen (no change)
            '/s$/' => '',        // dogs -> dog (general case, applied last)
        ];

        foreach ($rules as $pattern => $replacement) {
            if (preg_match($pattern, $tag)) {
                return preg_replace($pattern, $replacement, $tag);
            }
        }

        return $tag; // No plural form detected
    }

    /**
     * Check if a normalized tag is valid.
     *
     * @param string $tag Normalized tag
     * @return bool True if valid
     */
    protected function isValidTag(string $tag): bool
    {
        // Must not be empty
        if (empty($tag)) {
            return false;
        }

        // Must meet minimum length
        if (mb_strlen($tag, 'UTF-8') < self::MIN_TAG_LENGTH) {
            return false;
        }

        // Must not be only hyphens or special characters
        if (preg_match('/^[\-\s]*$/', $tag)) {
            return false;
        }

        // Must contain at least one letter or number
        if (!preg_match('/[a-zA-Z0-9]/', $tag)) {
            return false;
        }

        return true;
    }

    /**
     * Resolve synonym to canonical tag (tenant-scoped).
     *
     * @param string $tag Normalized tag
     * @param Tenant $tenant The tenant
     * @return string Canonical tag (may be the same as input)
     */
    protected function resolveSynonym(string $tag, Tenant $tenant): string
    {
        $cacheKey = "tag_synonyms:{$tenant->id}";
        
        $synonyms = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenant) {
            return DB::table('tag_synonyms')
                ->where('tenant_id', $tenant->id)
                ->pluck('canonical_tag', 'synonym_tag')
                ->toArray();
        });

        return $synonyms[$tag] ?? $tag;
    }

    /**
     * Check if a tag is blocked for this tenant.
     *
     * @param string $tag Canonical tag
     * @param Tenant $tenant The tenant
     * @return bool True if blocked
     */
    protected function isBlocked(string $tag, Tenant $tenant): bool
    {
        $cacheKey = "tag_blocked:{$tenant->id}";
        
        $blockedTags = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenant) {
            return DB::table('tag_rules')
                ->where('tenant_id', $tenant->id)
                ->where('rule_type', 'block')
                ->pluck('tag')
                ->toArray();
        });

        return in_array($tag, $blockedTags, true);
    }

    /**
     * Check if a tag is on the preferred list for this tenant.
     * 
     * This doesn't affect normalization but can be used later for prioritization.
     *
     * @param string $tag Canonical tag
     * @param Tenant $tenant The tenant
     * @return bool True if preferred
     */
    public function isPreferred(string $tag, Tenant $tenant): bool
    {
        $cacheKey = "tag_preferred:{$tenant->id}";
        
        $preferredTags = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenant) {
            return DB::table('tag_rules')
                ->where('tenant_id', $tenant->id)
                ->where('rule_type', 'preferred')
                ->pluck('tag')
                ->toArray();
        });

        return in_array($tag, $preferredTags, true);
    }

    /**
     * Normalize multiple tags at once.
     *
     * @param array $rawTags Array of raw tags
     * @param Tenant $tenant The tenant
     * @return array Array of canonical tags (excludes invalid/blocked ones)
     */
    public function normalizeMultiple(array $rawTags, Tenant $tenant): array
    {
        $canonical = [];
        
        foreach ($rawTags as $rawTag) {
            $normalized = $this->normalize($rawTag, $tenant);
            if ($normalized !== null && !in_array($normalized, $canonical, true)) {
                $canonical[] = $normalized;
            }
        }

        return $canonical;
    }

    /**
     * Check if two raw tags would normalize to the same canonical form.
     *
     * @param string $tag1 First raw tag
     * @param string $tag2 Second raw tag  
     * @param Tenant $tenant The tenant
     * @return bool True if they normalize to the same canonical tag
     */
    public function areEquivalent(string $tag1, string $tag2, Tenant $tenant): bool
    {
        $canonical1 = $this->normalize($tag1, $tenant);
        $canonical2 = $this->normalize($tag2, $tenant);
        
        return $canonical1 !== null && $canonical1 === $canonical2;
    }

    /**
     * Clear cached rules for a tenant (call after updating synonyms/rules).
     *
     * @param Tenant $tenant The tenant
     * @return void
     */
    public function clearCache(Tenant $tenant): void
    {
        Cache::forget("tag_synonyms:{$tenant->id}");
        Cache::forget("tag_blocked:{$tenant->id}");
        Cache::forget("tag_preferred:{$tenant->id}");
    }

    /**
     * Batch normalize and deduplicate tags, preserving order of first occurrence.
     *
     * @param array $rawTags Array of raw tags
     * @param Tenant $tenant The tenant
     * @return array ['canonical' => string[], 'blocked' => string[], 'invalid' => string[]]
     */
    public function normalizeBatch(array $rawTags, Tenant $tenant): array
    {
        $result = [
            'canonical' => [],
            'blocked' => [],
            'invalid' => [],
        ];

        $seen = [];

        foreach ($rawTags as $rawTag) {
            $normalized = $this->normalize($rawTag, $tenant);
            
            if ($normalized === null) {
                if ($this->isBlocked($this->performBasicNormalization($rawTag), $tenant)) {
                    $result['blocked'][] = $rawTag;
                } else {
                    $result['invalid'][] = $rawTag;
                }
            } elseif (!isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $result['canonical'][] = $normalized;
            }
            // Skip duplicates silently
        }

        return $result;
    }
}