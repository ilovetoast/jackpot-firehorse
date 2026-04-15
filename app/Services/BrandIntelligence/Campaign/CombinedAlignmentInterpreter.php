<?php

namespace App\Services\BrandIntelligence\Campaign;

use App\Enums\BrandAlignmentState;
use App\Models\CampaignAlignmentScore;
use App\Models\CollectionCampaignIdentity;
use App\Services\BrandIntelligence\Dimensions\DimensionResult;

/**
 * Interprets master brand alignment + campaign alignment together.
 *
 * The interpreter considers more than just rating x rating:
 * - campaign confidence
 * - campaign readiness sufficiency
 * - whether campaign scoring actually ran
 * - whether campaign had enough evaluable dimensions
 */
final class CombinedAlignmentInterpreter
{
    private const LOW_CONFIDENCE_THRESHOLD = 0.4;
    private const MIN_EVALUABLE_DIMENSIONS = 3;

    /**
     * @param  array|null  $masterPayload  The master brand intelligence payload (from brandIntelligencePayloadForFrontend)
     * @param  CampaignAlignmentScore|null  $campaignScore
     * @param  CollectionCampaignIdentity|null  $campaignIdentity
     * @return array<string, mixed>
     */
    public function interpret(
        ?array $masterPayload,
        ?CampaignAlignmentScore $campaignScore,
        ?CollectionCampaignIdentity $campaignIdentity,
    ): array {
        $masterRating = $masterPayload['v2_rating'] ?? $masterPayload['overall_score'] ?? null;
        $masterState = $masterPayload['v2_alignment_state'] ?? $masterPayload['alignment_state'] ?? null;
        $masterConfidence = $masterPayload['v2_overall_confidence'] ?? $masterPayload['confidence'] ?? null;

        $base = [
            'master_rating' => $masterRating,
            'master_state' => $masterState,
            'master_confidence' => $masterConfidence !== null ? round((float) $masterConfidence, 2) : null,
        ];

        if ($campaignIdentity === null) {
            return array_merge($base, [
                'campaign_rating' => null,
                'campaign_state' => null,
                'campaign_confidence' => null,
                'campaign_readiness' => null,
                'campaign_scored' => false,
                'campaign_data_sufficient' => false,
                'combined_key' => 'master_only',
                'interpretation_text' => 'Master brand alignment only; no campaign identity configured',
                'interpretation_caveat' => null,
                'primary_display' => 'master',
            ]);
        }

        $readiness = $campaignIdentity->readiness_status;
        $scoringEnabled = $campaignIdentity->scoring_enabled;

        if (! $scoringEnabled) {
            return array_merge($base, [
                'campaign_rating' => null,
                'campaign_state' => null,
                'campaign_confidence' => null,
                'campaign_readiness' => $readiness,
                'campaign_scored' => false,
                'campaign_data_sufficient' => false,
                'combined_key' => 'campaign_scoring_disabled',
                'interpretation_text' => 'Campaign scoring is not enabled for this collection',
                'interpretation_caveat' => null,
                'primary_display' => 'master',
            ]);
        }

        if ($readiness === CollectionCampaignIdentity::READINESS_INCOMPLETE) {
            return array_merge($base, [
                'campaign_rating' => null,
                'campaign_state' => null,
                'campaign_confidence' => null,
                'campaign_readiness' => $readiness,
                'campaign_scored' => false,
                'campaign_data_sufficient' => false,
                'combined_key' => 'campaign_identity_incomplete',
                'interpretation_text' => 'Campaign identity is incomplete; scoring requires more configuration',
                'interpretation_caveat' => null,
                'primary_display' => 'master',
            ]);
        }

        if ($campaignScore === null) {
            return array_merge($base, [
                'campaign_rating' => null,
                'campaign_state' => null,
                'campaign_confidence' => null,
                'campaign_readiness' => $readiness,
                'campaign_scored' => false,
                'campaign_data_sufficient' => false,
                'combined_key' => 'campaign_not_scored',
                'interpretation_text' => 'Campaign alignment has not been scored yet',
                'interpretation_caveat' => null,
                'primary_display' => 'master',
            ]);
        }

        $campaignRating = $campaignScore->overall_score;
        $campaignConfidence = $campaignScore->confidence;
        $campaignLevel = $campaignScore->level;
        $campaignBreakdown = $campaignScore->breakdown_json ?? [];

        $evaluableDims = 0;
        foreach ($campaignBreakdown['dimensions'] ?? [] as $dim) {
            if (($dim['evaluable'] ?? false) === true) {
                $evaluableDims++;
            }
        }

        $dataSufficient = $evaluableDims >= self::MIN_EVALUABLE_DIMENSIONS;
        $lowConfidence = ($campaignConfidence ?? 0.0) < self::LOW_CONFIDENCE_THRESHOLD;

        if (! $dataSufficient) {
            return array_merge($base, [
                'campaign_rating' => $campaignRating,
                'campaign_state' => $campaignLevel,
                'campaign_confidence' => $campaignConfidence !== null ? round((float) $campaignConfidence, 2) : null,
                'campaign_readiness' => $readiness,
                'campaign_scored' => true,
                'campaign_data_sufficient' => false,
                'combined_key' => 'campaign_insufficient_data',
                'interpretation_text' => 'Campaign alignment could not be fully assessed; identity may need more content',
                'interpretation_caveat' => sprintf('Only %d of 6 campaign dimensions were evaluable', $evaluableDims),
                'primary_display' => 'master',
            ]);
        }

        $masterStrong = $masterRating !== null && $masterRating >= 3;
        $masterWeak = $masterRating !== null && $masterRating <= 1;
        $campaignStrong = $campaignRating !== null && $campaignRating >= 3;
        $campaignWeak = $campaignRating !== null && $campaignRating <= 1;

        if ($lowConfidence) {
            $combinedKey = 'campaign_promising_low_confidence';
            $text = $campaignStrong
                ? 'Campaign alignment appears promising, but confidence is limited'
                : 'Campaign alignment is uncertain due to limited evidence';
            $caveat = 'Campaign confidence is limited due to thin identity configuration';
            $primary = 'campaign';
        } elseif ($masterStrong && $campaignStrong) {
            $combinedKey = 'fully_aligned';
            $text = 'Fully aligned with brand and campaign';
            $caveat = null;
            $primary = 'campaign';
        } elseif (! $masterStrong && $campaignStrong) {
            $combinedKey = 'campaign_strong_with_expected_deviation';
            $text = 'Strong campaign fit with expected deviation from base brand';
            $caveat = null;
            $primary = 'campaign';
        } elseif ($masterStrong && ! $campaignStrong) {
            $combinedKey = 'on_brand_weak_campaign';
            $text = 'On brand, but not well aligned to this campaign';
            $caveat = null;
            $primary = 'campaign';
        } elseif ($masterWeak && $campaignWeak) {
            $combinedKey = 'off_brand_both';
            $text = 'Off-brand in both base and campaign context';
            $caveat = null;
            $primary = 'campaign';
        } else {
            $combinedKey = 'mixed';
            $text = 'Partial alignment with both brand and campaign';
            $caveat = null;
            $primary = 'campaign';
        }

        if ($readiness === CollectionCampaignIdentity::READINESS_PARTIAL && $caveat === null) {
            $caveat = 'Campaign identity is partial; results may improve with more configuration';
        }

        return array_merge($base, [
            'campaign_rating' => $campaignRating,
            'campaign_state' => $campaignLevel,
            'campaign_confidence' => $campaignConfidence !== null ? round((float) $campaignConfidence, 2) : null,
            'campaign_readiness' => $readiness,
            'campaign_scored' => true,
            'campaign_data_sufficient' => $dataSufficient,
            'combined_key' => $combinedKey,
            'interpretation_text' => $text,
            'interpretation_caveat' => $caveat,
            'primary_display' => $primary,
        ]);
    }
}
