<?php

namespace App\Services\AI\Insights;

use Illuminate\Support\Facades\DB;

/**
 * Tracks dismissals so repeated rejects suppress future sync churn for the same logical suggestion.
 */
class AiSuggestionSuppressionService
{
    public const REJECT_THRESHOLD = 2;

    public static function normalizeValueKey(string $fieldKey, string $suggestedValue): string
    {
        return mb_strtolower(trim($fieldKey)).'|'.mb_strtolower(trim($suggestedValue));
    }

    public static function normalizeFieldKey(string $categorySlug, string $fieldKey, string $sourceCluster): string
    {
        return mb_strtolower(trim($categorySlug)).'|'
            .mb_strtolower(trim($fieldKey)).'|'
            .mb_strtolower(trim($sourceCluster));
    }

    public function isSuppressed(int $tenantId, string $suggestionType, string $normalizedKey): bool
    {
        $row = DB::table('ai_suggestion_feedback')
            ->where('tenant_id', $tenantId)
            ->where('suggestion_type', $suggestionType)
            ->where('normalized_key', $normalizedKey)
            ->first();

        if (! $row) {
            return false;
        }

        return (int) $row->rejected_count >= self::REJECT_THRESHOLD;
    }

    public function recordRejection(int $tenantId, string $suggestionType, string $normalizedKey): void
    {
        $now = now();
        $existing = DB::table('ai_suggestion_feedback')
            ->where('tenant_id', $tenantId)
            ->where('suggestion_type', $suggestionType)
            ->where('normalized_key', $normalizedKey)
            ->first();

        if ($existing) {
            DB::table('ai_suggestion_feedback')
                ->where('id', $existing->id)
                ->update([
                    'rejected_count' => (int) $existing->rejected_count + 1,
                    'updated_at' => $now,
                ]);

            return;
        }

        DB::table('ai_suggestion_feedback')->insert([
            'tenant_id' => $tenantId,
            'suggestion_type' => $suggestionType,
            'normalized_key' => $normalizedKey,
            'rejected_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
