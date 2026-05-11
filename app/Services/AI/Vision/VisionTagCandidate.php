<?php

namespace App\Services\AI\Vision;

/**
 * Single image tag candidate produced by a {@see VisionTagCandidateProvider}.
 *
 * Providers (e.g. AWS Rekognition DetectLabels) return raw label data; we shape it into
 * normalized candidates here so the existing {@see \App\Services\AiMetadataGenerationService}
 * sanitizer/blocklist/category-ban pipeline can decide whether to persist.
 *
 * Confidence is normalized to a 0–1 scale to match the rest of the AI metadata system.
 * Provenance fields (raw_label_name, raw_categories, raw_parents, evidence) are kept for
 * admin/debug diagnostics — they are NOT used as tag values.
 */
final class VisionTagCandidate
{
    /**
     * @param  string  $value  Sanitized tag string (post-canonicalization happens later)
     * @param  float  $confidence  0–1 (Rekognition's 0–100 is converted upstream)
     * @param  string  $provider  e.g. 'aws_rekognition'
     * @param  string|null  $evidence  Human-readable provenance (e.g. "aws rekognition label: Soccer, confidence 98.2")
     * @param  string|null  $rawLabelName  Original provider label name before our normalization
     * @param  list<string>  $rawCategories  Provider-supplied label categories (Rekognition Categories)
     * @param  list<string>  $rawParents  Provider-supplied parent labels (Rekognition Parents)
     */
    public function __construct(
        public readonly string $value,
        public readonly float $confidence,
        public readonly string $provider,
        public readonly ?string $evidence = null,
        public readonly ?string $rawLabelName = null,
        public readonly array $rawCategories = [],
        public readonly array $rawParents = [],
    ) {
    }
}
