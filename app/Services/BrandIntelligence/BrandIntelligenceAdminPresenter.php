<?php

namespace App\Services\BrandIntelligence;

/**
 * Explanations for the internal Brand Intelligence admin debugger (scoring path, AI gates, simulate delta).
 */
final class BrandIntelligenceAdminPresenter
{
    /**
     * @return array{
     *     signals: array{status: string, title: string, text: string},
     *     reference_similarity: array{status: string, title: string, text: string},
     *     ai: array{status: string, title: string, text: string}
     * }
     */
    public static function scoringPath(
        array $breakdown,
        float $confidence,
        string $level,
        bool $aiUsed,
        ?string $assetMimeType = null,
    ): array {
        $signals = $breakdown['signals'] ?? null;
        $signalsOk = is_array($signals);

        $ref = $breakdown['reference_similarity'] ?? [];
        $refsUsed = ! empty($ref['used']);

        $aiLine = self::aiPathLine($breakdown, $confidence, $level, $aiUsed, $assetMimeType);

        return [
            'signals' => [
                'status' => $signalsOk ? 'ok' : 'warn',
                'title' => 'Signals',
                'text' => $signalsOk ? 'Text / typography / visual evaluated' : 'Signals block missing',
            ],
            'reference_similarity' => [
                'status' => $refsUsed ? 'ok' : 'warn',
                'title' => 'Reference similarity',
                'text' => $refsUsed ? 'Compared to brand reference embeddings' : 'Not used (no refs, no asset embedding, or vector mismatch)',
            ],
            'ai' => [
                'status' => $aiLine['status'],
                'title' => 'AI insight',
                'text' => $aiLine['text'],
            ],
        ];
    }

    /**
     * @return array{heading: string, lines: list<string>, ai_used: bool}
     */
    public static function aiExplanation(
        array $breakdown,
        float $confidence,
        string $level,
        bool $aiUsed,
        ?string $assetMimeType = null,
    ): array {
        if ($aiUsed) {
            $lines = self::aiTriggersWhenRan($breakdown, $confidence);

            return [
                'heading' => 'AI trigger (why insight ran)',
                'lines' => $lines,
                'ai_used' => true,
            ];
        }

        $skip = self::firstAiSkipReason($breakdown, $confidence, $level, $assetMimeType);

        return [
            'heading' => 'Why AI did not run',
            'lines' => $skip !== null ? [$skip] : [],
            'ai_used' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $stored  Serialized stored score row (level, confidence, breakdown_json, …)
     * @param  array<string, mixed>  $simulated  Engine payload from dry-run
     * @return array{
     *     current: array{level: string, confidence: float, reference_score: int|null, reference_normalized: float|null, alignment_state: string|null},
     *     simulated: array{level: string, confidence: float, reference_score: int|null, reference_normalized: float|null, alignment_state: string|null},
     *     changes: list<string>
     * }
     */
    public static function simulateDelta(array $stored, array $simulated): array
    {
        $oldB = $stored['breakdown_json'] ?? [];
        $newB = $simulated['breakdown_json'] ?? [];
        $oldRef = is_array($oldB) ? ($oldB['reference_similarity'] ?? []) : [];
        $newRef = is_array($newB) ? ($newB['reference_similarity'] ?? []) : [];

        $changes = [];
        $oldNorm = $oldRef['normalized'] ?? null;
        $newNorm = $newRef['normalized'] ?? null;
        if ($oldNorm !== null && $newNorm !== null && (float) $oldNorm !== (float) $newNorm) {
            $changes[] = (float) $newNorm > (float) $oldNorm
                ? 'Reference similarity increased'
                : 'Reference similarity decreased';
        } elseif ($oldNorm === null && $newNorm !== null) {
            $changes[] = 'Reference similarity now computed';
        }

        $os = $oldRef['score'] ?? null;
        $ns = $newRef['score'] ?? null;
        if ($os !== null && $ns !== null && (int) $os !== (int) $ns) {
            $changes[] = (int) $ns > (int) $os ? 'Reference score increased' : 'Reference score decreased';
        }

        $oc = (float) $stored['confidence'];
        $nc = (float) $simulated['confidence'];
        if ($nc > $oc) {
            $changes[] = 'Confidence improved';
        } elseif ($nc < $oc) {
            $changes[] = 'Confidence decreased';
        }

        $ol = (string) ($stored['level'] ?? '');
        $nl = (string) ($simulated['level'] ?? '');
        if ($ol !== '' && $nl !== '' && $ol !== $nl) {
            $changes[] = 'Level changed';
        }

        $oa = is_array($oldB) && isset($oldB['alignment_state']) ? (string) $oldB['alignment_state'] : null;
        $na = is_array($newB) && isset($newB['alignment_state']) ? (string) $newB['alignment_state'] : null;
        if ($oa !== null && $na !== null && $oa !== $na) {
            $changes[] = 'Alignment state changed';
        }

        return [
            'current' => [
                'level' => $ol,
                'confidence' => $oc,
                'reference_score' => isset($oldRef['score']) && is_numeric($oldRef['score']) ? (int) $oldRef['score'] : null,
                'reference_normalized' => is_numeric($oldNorm ?? null) ? (float) $oldNorm : null,
                'alignment_state' => $oa,
            ],
            'simulated' => [
                'level' => $nl,
                'confidence' => $nc,
                'reference_score' => isset($newRef['score']) && is_numeric($newRef['score']) ? (int) $newRef['score'] : null,
                'reference_normalized' => is_numeric($newNorm ?? null) ? (float) $newNorm : null,
                'alignment_state' => $na,
            ],
            'changes' => array_values(array_unique($changes)),
        ];
    }

    /**
     * Conditions that align with {@see BrandIntelligenceEngine::generateAIInsight()} — shown when AI produced an insight.
     *
     * @return list<string>
     */
    public static function aiTriggersWhenRan(array $breakdown, float $confidence): array
    {
        $triggers = [];
        $ref = $breakdown['reference_similarity'] ?? [];
        if (empty($ref['used'])) {
            $triggers[] = 'No reference similarity used (no refs, missing embeddings, or vector mismatch)';
        }
        if ($confidence < 0.7) {
            $triggers[] = 'Scoring confidence below 0.7';
        }

        return $triggers;
    }

    /**
     * First matching skip reason in the same order as {@see BrandIntelligenceEngine::generateAIInsight()}.
     */
    public static function firstAiSkipReason(
        array $breakdown,
        float $confidence,
        string $level,
        ?string $assetMimeType = null,
    ): ?string {
        if ($level === 'high' && $confidence >= 0.8) {
            return 'AI skipped (high level + confidence ≥ 0.8)';
        }
        if (count($breakdown['recommendations'] ?? []) >= 2) {
            return 'AI skipped (two recommendations already present)';
        }
        $ref = $breakdown['reference_similarity'] ?? [];
        if (! empty($ref['used']) && $confidence >= 0.7) {
            return 'AI skipped (reference similarity used + confidence ≥ 0.7)';
        }
        if ($assetMimeType !== null && $assetMimeType !== '' && ! str_starts_with($assetMimeType, 'image/')) {
            return 'AI skipped (not an image asset)';
        }

        return 'AI eligible but no insight returned (no thumbnail, vision API error, or parse failure)';
    }

    /**
     * @return array{status: string, text: string}
     */
    protected static function aiPathLine(
        array $breakdown,
        float $confidence,
        string $level,
        bool $aiUsed,
        ?string $assetMimeType = null,
    ): array {
        if ($aiUsed) {
            return ['status' => 'ok', 'text' => 'Vision insight produced'];
        }

        $text = self::firstAiSkipReason($breakdown, $confidence, $level, $assetMimeType) ?? 'Skipped or unavailable';

        return ['status' => 'skip', 'text' => $text];
    }
}
