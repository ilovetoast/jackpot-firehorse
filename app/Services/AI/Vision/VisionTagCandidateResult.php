<?php

namespace App\Services\AI\Vision;

/**
 * Result envelope returned by a {@see VisionTagCandidateProvider}.
 *
 * Carries both candidates and per-call provenance/usage so the caller
 * ({@see \App\Services\AiMetadataGenerationService}) can:
 *   - feed candidates through the existing sanitizer/category bans
 *   - record an AIAgentRun + ai_usage row with non-token billing units
 *   - surface raw labels in admin diagnostics without leaking presigned URLs
 *
 * Image tags are billed per-API-call (Rekognition), so token counts are 0 and
 * unit_count = 1 image. estimated_cost_usd comes from config, not the response.
 */
final class VisionTagCandidateResult
{
    /**
     * @param  string  $provider  e.g. 'aws_rekognition'
     * @param  string  $model  e.g. 'rekognition-detect-labels'
     * @param  string  $sourceType  e.g. 's3_object'
     * @param  string  $sourceBucket  S3 bucket name actually sent to the provider
     * @param  string  $sourceKey  S3 object key actually sent to the provider (preview when original unsupported)
     * @param  string|null  $sourceMime
     * @param  string|null  $sourceAssetVersionId
     * @param  int|null  $sourceWidth
     * @param  int|null  $sourceHeight
     * @param  array<string, mixed>  $rawResponse  Raw provider response (admin/debug only — not exposed to end users)
     * @param  list<VisionTagCandidate>  $candidates
     * @param  array{
     *   input_tokens: int,
     *   output_tokens: int,
     *   total_tokens: int,
     *   unit_type: string,
     *   unit_count: int,
     *   estimated_cost_usd: float,
     *   credits: int,
     *   features: list<string>,
     *   max_labels: int,
     *   min_confidence: float,
     * }  $usage
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $sourceType,
        public readonly string $sourceBucket,
        public readonly string $sourceKey,
        public readonly ?string $sourceMime,
        public readonly ?string $sourceAssetVersionId,
        public readonly ?int $sourceWidth,
        public readonly ?int $sourceHeight,
        public readonly array $rawResponse,
        public readonly array $candidates,
        public readonly array $usage,
    ) {
    }
}
