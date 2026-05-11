# AWS Rekognition vision tag candidates

Image tag candidates (the free-form tags that show up under
`asset_tag_candidates`) are produced by AWS Rekognition `DetectLabels` when
`AI_METADATA_VISION_PROVIDER=aws_rekognition` (the default). Structured
metadata field candidates (`asset_metadata_candidates`) still come from OpenAI
vision — Rekognition is **image tags only**.

## Why a separate provider?

OpenAI vision was hallucinating tags on packaging, sell sheets, and renders.
Rekognition is conservative, billed per image (not tokens), and we already
have every asset on S3 with IAM-authenticated workers. Splitting the two also
means the OpenAI prompt no longer asks for tags in the same call — it is a
fields-only prompt — which has stopped a class of leakage where prompt
examples turned into tags.

## Reverting

To revert to the legacy combined OpenAI vision call (fields + tags in one
request), set:

```
AI_METADATA_VISION_PROVIDER=openai
```

No code change needed. The Rekognition agent, classes, and tests remain in
place; the wiring just stops calling them.

## Configuration

`config/ai.php` → `metadata_tagging.aws_rekognition`:

| Env var | Default | Purpose |
| --- | --- | --- |
| `AI_METADATA_VISION_PROVIDER` | `aws_rekognition` | `aws_rekognition` or `openai` (revert) |
| `AI_METADATA_REKOGNITION_ENABLED` | `true` | Master kill switch for the provider |
| `AI_METADATA_REKOGNITION_REGION` | `AWS_DEFAULT_REGION` | Must match the bucket region |
| `AI_METADATA_REKOGNITION_MAX_LABELS` | `20` | `MaxLabels` parameter |
| `AI_METADATA_REKOGNITION_MIN_CONFIDENCE` | `70` | 0–100 (Rekognition scale) |
| `AI_METADATA_REKOGNITION_COST_USD_PER_IMAGE` | `0.001` | GENERAL_LABELS cost per call |
| `AI_METADATA_REKOGNITION_IMAGE_PROPERTIES` | `false` | Add `IMAGE_PROPERTIES` (separate AWS charge) |
| `AI_METADATA_REKOGNITION_IMAGE_PROPERTIES_COST_USD_PER_IMAGE` | `0.00075` | Cost when `IMAGE_PROPERTIES` is enabled |
| `AI_METADATA_REKOGNITION_FALLBACK_TO_OPENAI` | `false` | When `true`, fall back to OpenAI tags-only on Rekognition failure |
| `AI_METADATA_REKOGNITION_MIN_CREDITS` | `0` | Optional minimum billable credit floor |

Agent registry (`config/ai.php` → `agents.metadata_image_tags_rekognition`)
declares `provider=aws_rekognition`, `model=rekognition-detect-labels`,
`capability=vision_tagging`, `cost_unit=image`.

## Source-image rules (image tags only)

The provider picks the best supported source for Rekognition in this order:

1. The **original** S3 object — only when MIME and extension are JPEG or PNG.
2. The **medium thumbnail** — when its extension is JPEG or PNG.
3. The **preview raster** (e.g. PDF page render) — same JPEG/PNG gate.

Anything else (PSD/AI/PDF/WEBP/TIFF/video) **without** a JPEG/PNG preview
yields a `RuntimeException` and the asset gets zero AI tags for that run —
we do not retry forever and do not synthesize a URL.

CloudFront URLs and presigned URLs are **never** sent to Rekognition; the
SDK call uses `Image.S3Object.{Bucket, Name}` so AWS reads the object via
IAM.

## Required IAM (worker role / user)

Least-privilege policy. Avoid `AmazonRekognitionFullAccess` in production.

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": ["rekognition:DetectLabels"],
            "Resource": "*"
        },
        {
            "Effect": "Allow",
            "Action": ["s3:GetObject"],
            "Resource": [
                "arn:aws:s3:::YOUR_BUCKET/assets/*",
                "arn:aws:s3:::YOUR_BUCKET/temp/uploads/*"
            ]
        }
    ]
}
```

If S3 objects are encrypted with SSE-KMS, also grant `kms:Decrypt` for the
relevant key. The Rekognition region must match the bucket region.

## Cost & usage tracking

Each Rekognition `DetectLabels` call writes one `AIAgentRun` row with:

- `agent_id = metadata_image_tags_rekognition`
- `model_used = rekognition-detect-labels`
- `tokens_in = 0`, `tokens_out = 0` (Rekognition is **not** token-billed)
- `estimated_cost = cost_usd_per_image` from config (`+ image_properties_cost_usd_per_image` when `IMAGE_PROPERTIES` is on)
- `metadata.billing_type = "per_image"`, `unit_type = "image"`, `unit_count = 1`
- `metadata.features`, `max_labels`, `min_confidence`
- `metadata.source = "s3_object"`, `source_bucket`, `source_key`, `source_mime`
- `metadata.raw_label_count`, `accepted_tag_count`, plus per-rejection counts

The shared `tagging` credit weight (`config/ai_credits.php`) still bills the
unified credit pool, so a Rekognition call counts as one tagging credit just
like the legacy OpenAI vision call did.

## Sanitation pipeline

Rekognition labels are candidates only. Every label runs through the same
pipeline used for OpenAI vision tags before persistence:

1. `canonicalizeAiVisionTagString` (lowercase, separator strip, etc.)
2. `applyVisionTagPhraseAliases` (e.g. `ui screenshot` → `screen capture`)
3. `getVisionTagSanitizerRejectionReason`
   - word count > 3 → reject
   - prompt-leakage phrases → reject
   - provenance/pipeline phrases (`from vision`, `ai generated`, …) → reject
   - **category restatement** (logo on a Logos category, photo on Photography, …)
   - generic filler (`studio`, `lighting setup`, `model`, …)
4. `vision_tag_blocklist` (env: `AI_METADATA_VISION_TAG_BLOCKLIST`)
5. Photoshoot redundancy + duplicate detection

Raw Rekognition `Categories` and `Parents` are stored as evidence on the
`VisionTagCandidate` for admin/debug use only — they are **not** persisted as
tags.

## Error handling

`AiMetadataGenerationService::classifyRekognitionFailure` distinguishes:

- **Retryable** (`ThrottlingException`, `ProvisionedThroughputExceededException`,
  `ServerException`) — re-thrown so the queue worker retries with backoff.
- **Permanent** (`InvalidS3ObjectException`, `InvalidImageFormatException`,
  `ImageTooLargeException`, `AccessDeniedException`,
  `InvalidParameterException`) — agent run marked failed, OpenAI fallback runs
  if enabled, otherwise zero tags for this asset run.
- **Unknown** — same as permanent.

## Tests

- `tests/Unit/Services/AwsRekognitionVisionTagProviderTest.php` exercises the
  provider directly with a mocked `RekognitionClient`: S3Object payload,
  unsupported-format fallback to thumbnail, IMAGE_PROPERTIES gating, cost,
  per-image unit accounting, and AWS errors.
- `tests/Unit/Services/AiMetadataGenerationServiceRekognitionTest.php`
  exercises the wired-in path with `AI_METADATA_VISION_PROVIDER=aws_rekognition`:
  tags-only path skips OpenAI entirely, combined path uses fields-only OpenAI
  prompt, sanitizer/category bans still apply, AIAgentRun is recorded with
  zero tokens, fallback path runs OpenAI tags-only.

Run with Sail:

```
sail test --filter='AwsRekognitionVisionTagProviderTest|AiMetadataGenerationServiceRekognitionTest'
```
