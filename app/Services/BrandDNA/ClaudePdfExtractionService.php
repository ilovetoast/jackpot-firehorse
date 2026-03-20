<?php

namespace App\Services\BrandDNA;

use App\Enums\AITaskType;
use App\Models\AIAgentRun;
use App\Models\Asset;
use App\Services\AI\Providers\AnthropicProvider;
use App\Services\BrandDNA\Extraction\BrandExtractionSchema;
use App\Services\PdfPageRenderingService;
use Illuminate\Support\Facades\Log;

/**
 * Single-pass PDF extraction using Claude's native PDF support.
 * Sends the entire PDF in one API call and maps the response to
 * BrandExtractionSchema so it plugs directly into BrandSnapshotService.
 *
 * Creates an AIAgentRun for every call so it appears on the AI dashboard
 * with token counts, cost, and run history.
 */
class ClaudePdfExtractionService
{
    public function __construct(
        protected PdfPageRenderingService $pdfRenderingService,
        protected AnthropicProvider $anthropicProvider
    ) {}

    /**
     * Download the asset's PDF from S3, send it to Claude, return a
     * BrandExtractionSchema-compatible array and the raw API response.
     *
     * @return array{extraction: array, raw_response: array}
     */
    public function extract(Asset $asset): array
    {
        $version = $asset->currentVersion;
        $tempPath = $this->pdfRenderingService->downloadSourcePdfToTemp($asset, $version);

        $rawSize = filesize($tempPath);
        $useFilesApi = $rawSize > \App\Models\BrandPipelineRun::MAX_VISION_PDF_BYTES;

        if (! $useFilesApi) {
            try {
                $pdfBase64 = base64_encode(file_get_contents($tempPath));
            } finally {
                @unlink($tempPath);
            }
        }

        $prompt = $this->buildPrompt();
        $modelName = config('ai.agents.brand_pdf_extractor.default_model', 'claude-sonnet-4-20250514');

        $agentRun = AIAgentRun::create([
            'agent_id' => 'brand_pdf_extractor',
            'agent_name' => 'Brand PDF Extractor',
            'triggering_context' => 'tenant',
            'environment' => app()->environment(),
            'tenant_id' => $asset->tenant_id ?? null,
            'task_type' => AITaskType::BRAND_PDF_EXTRACTION,
            'entity_type' => 'asset',
            'entity_id' => (string) $asset->id,
            'model_used' => $modelName,
            'tokens_in' => 0,
            'tokens_out' => 0,
            'estimated_cost' => 0,
            'status' => 'failed',
            'started_at' => now(),
            'metadata' => [
                'asset_id' => $asset->id,
                'pdf_size_bytes' => $rawSize,
                'method' => $useFilesApi ? 'claude_files_api' : 'claude_single_pass',
            ],
        ]);

        Log::channel('pipeline')->info('[ClaudePdfExtractionService] Sending PDF to Claude', [
            'asset_id' => $asset->id,
            'agent_run_id' => $agentRun->id,
            'pdf_size_bytes' => $rawSize,
            'method' => $useFilesApi ? 'files_api' : 'inline_base64',
        ]);

        try {
            if ($useFilesApi) {
                $response = $this->anthropicProvider->analyzePdfViaFilesApi($tempPath, $prompt, [
                    'model' => $modelName,
                    'max_tokens' => 8192,
                ]);
                @unlink($tempPath);
            } else {
                $response = $this->anthropicProvider->analyzePdf($pdfBase64, $prompt, [
                    'model' => $modelName,
                    'max_tokens' => 8192,
                ]);
            }

            $cost = $this->anthropicProvider->calculateCost(
                $response['tokens_in'],
                $response['tokens_out'],
                $response['model']
            );

            $extraction = $this->parseResponse($response['text']);
            $fieldsExtracted = $this->countExtractedFields($extraction);

            $agentRun->markAsSuccessful(
                $response['tokens_in'],
                $response['tokens_out'],
                $cost,
                array_merge($agentRun->metadata ?? [], [
                    'fields_extracted' => $fieldsExtracted,
                    'model' => $response['model'],
                    'section_confidence' => $extraction['section_confidence'] ?? [],
                    'extraction_notes' => $extraction['_extraction_notes'] ?? [],
                    'prompt' => config('ai.logging.store_prompts', false) ? $prompt : null,
                    'response' => config('ai.logging.store_prompts', false) ? $response['text'] : null,
                ]),
                null,
                $extraction['confidence'] ?? null,
                "Extracted {$fieldsExtracted} brand DNA fields from PDF"
            );

            Log::channel('pipeline')->info('[ClaudePdfExtractionService] Claude response received', [
                'asset_id' => $asset->id,
                'agent_run_id' => $agentRun->id,
                'tokens_in' => $response['tokens_in'],
                'tokens_out' => $response['tokens_out'],
                'cost' => $cost,
                'fields_extracted' => $fieldsExtracted,
                'model' => $response['model'],
                'section_confidence' => $extraction['section_confidence'] ?? [],
                'extraction_notes' => $extraction['_extraction_notes'] ?? [],
                'identity_mission' => mb_substr($extraction['identity']['mission'] ?? '', 0, 120) ?: null,
                'identity_positioning' => mb_substr($extraction['identity']['positioning'] ?? '', 0, 120) ?: null,
                'identity_tagline' => $extraction['identity']['tagline'] ?? null,
                'identity_beliefs' => $extraction['identity']['beliefs'] ?? [],
                'identity_values' => $extraction['identity']['values'] ?? [],
                'personality_archetype' => $extraction['personality']['primary_archetype'] ?? null,
                'personality_traits' => $extraction['personality']['traits'] ?? [],
                'personality_tone' => $extraction['personality']['tone_keywords'] ?? [],
                'visual_primary_colors' => $extraction['visual']['primary_colors'] ?? [],
                'visual_secondary_colors' => $extraction['visual']['secondary_colors'] ?? [],
                'visual_fonts' => $extraction['visual']['fonts'] ?? [],
                'visual_style' => $extraction['visual']['visual_style'] ?? null,
                'photography_style' => $extraction['visual']['photography_style'] ?? null,
                'design_cues' => $extraction['visual']['design_cues'] ?? [],
                'typography' => $extraction['typography'] ?? [],
            ]);

            Log::channel('pipeline')->info('[ClaudePdfExtractionService] Raw Claude JSON', [
                'asset_id' => $asset->id,
                'raw_json' => $response['text'],
            ]);

            return [
                'extraction' => $extraction,
                'raw_response' => [
                    'text' => $response['text'],
                    'model' => $response['model'],
                    'tokens_in' => $response['tokens_in'],
                    'tokens_out' => $response['tokens_out'],
                    'cost' => $cost,
                    'extracted_at' => now()->toIso8601String(),
                ],
            ];
        } catch (\Throwable $e) {
            if ($useFilesApi && isset($tempPath) && file_exists($tempPath)) {
                @unlink($tempPath);
            }
            $agentRun->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    protected function buildPrompt(): string
    {
        return <<<'PROMPT'
You are a brand strategist analyzing a brand guidelines PDF. Your job is to extract both EXPLICIT declarations and IMPLICIT signals about the brand's identity.

Brand guidelines documents rarely use our exact field names. You must interpret the document through a brand strategy lens.

IMPORTANT: You are NOT extracting images. Do not attempt to reproduce logos or graphics. Instead, DESCRIBE visual elements — what the logo looks like, what photography guidelines say, what design principles are specified.

═══════════════════════════════════════
IDENTITY — The brand's strategic foundation
═══════════════════════════════════════

mission (WHY — Purpose):
  The reason this brand exists beyond making money. The driving force.
  LOOK FOR: "Our purpose", "Why we exist", "Our mission", "We believe", "We're here to", purpose statements, founder's vision, opening manifestos.
  EXAMPLE: Patagonia → "We're in business to save our home planet"
  Return the most concise, powerful statement. Verbatim if a clear declaration exists; synthesize from context if the purpose is clearly communicated but not in one sentence.

vision (WHERE — Future state):
  What the brand wants the world to look like. The aspirational end-state.
  LOOK FOR: "Our vision", "We envision", "We're working toward", future-state language.
  EXAMPLE: IKEA → "To create a better everyday life for the many people"
  Often missing from brand guidelines — return null if not present.

positioning (WHAT — Promise / Value Proposition):
  What the brand delivers, its competitive promise, its unique value to customers.
  LOOK FOR: "What we do", "Our promise", "We deliver", "What makes us different", elevator pitches, brand propositions, value statements, competitive differentiation.
  EXAMPLE: Volvo → "The safest cars on the road" / Apple → "Premium technology that just works"
  This is the brand's answer to "what do you actually do/offer?"

industry:
  The market category or sector. LOOK FOR: industry mentions, "we operate in", product/service descriptions.
  Return a short label like "Athletic Footwear", "SaaS / Project Management", "Premium Coffee".

target_audience:
  Who the brand serves. The primary customer or user segment.
  LOOK FOR: "Our audience", "We serve", "Our customers", personas, demographic descriptions, "For [type of person]".
  EXAMPLE: "Health-conscious millennials", "Enterprise IT decision-makers", "Serious weightlifters and gym enthusiasts"
  Synthesize from context if the audience is clearly implied but not explicitly stated.

tagline:
  The brand's public-facing slogan or strapline.
  LOOK FOR: Large-type phrases on cover or hero pages, "Our tagline", lock-up text near logos.
  EXAMPLE: Nike → "Just Do It"

beliefs:
  Core convictions that shape decisions. Not marketing copy — genuine organizational beliefs.
  LOOK FOR: "We believe", "Our principles", manifesto statements, founding philosophy.
  Return as array of short strings. Max 5-6.

values:
  Named brand values, often presented as a list.
  LOOK FOR: "Our values", "What we stand for", value cards/icons, cultural principles.
  Return as array of value names. Max 5-6.

═══════════════════════════════════════
PERSONALITY — How the brand behaves and communicates
═══════════════════════════════════════

primary_archetype:
  The dominant brand archetype (Jungian model). May be explicitly stated or strongly implied by tone, imagery, and language patterns.
  Must be one of: Hero, Creator, Explorer, Sage, Innocent, Outlaw, Magician, Everyman, Lover, Caregiver, Jester, Ruler.
  INTERPRETATION GUIDE:
  - Bold, competitive, "we can do it" → Hero
  - Imaginative, innovative, artistic → Creator
  - Freedom, discovery, adventure → Explorer
  - Knowledge, expertise, truth-seeking → Sage
  - Purity, simplicity, optimism → Innocent
  - Rebellious, disruptive, rule-breaking → Outlaw
  - Transformative, visionary → Magician
  - Relatable, down-to-earth, inclusive → Everyman
  - Passion, intimacy, sensory → Lover
  - Nurturing, protective, service → Caregiver
  - Playful, humorous, entertainment → Jester
  - Authority, control, premium → Ruler
  Return null only if there truly isn't enough signal.

traits:
  Personality adjectives that describe the brand as if it were a person.
  LOOK FOR: "Brand personality", "We are", "Our character", adjective lists, personality wheels.
  ALSO INFER FROM: tone of writing, imagery style, color choices.
  Return 3-8 single-word or two-word traits.

tone_keywords:
  Voice and communication style descriptors.
  LOOK FOR: "Tone of voice", "How we speak", "Our voice is", "Writing guidelines", "Brand voice", do's and don'ts of language.
  ALSO INFER FROM: the actual writing style used in the document itself.
  Return 3-8 descriptors like "confident", "warm", "direct", "playful".

voice_description:
  A 1-3 sentence summary of the brand's communication voice and style.
  Synthesize from tone of voice sections, writing guidelines, do/don't language rules, and the document's own writing style.
  EXAMPLE: "Bold and direct with a coaching mentality. Uses short, punchy sentences. Avoids corporate jargon — speaks like a trusted training partner."
  Return null if insufficient signal.

brand_look:
  A 1-3 sentence summary of the overall visual and design direction.
  Synthesize from layout principles, imagery guidelines, graphic element descriptions, and the document's own visual design.
  EXAMPLE: "Clean and geometric with strong contrast. Uses bold red accents against dark backgrounds. Photography is lifestyle-focused with dramatic lighting."
  Return null if insufficient signal.

═══════════════════════════════════════
VISUAL — Design system signals
═══════════════════════════════════════

NOTE: Do NOT extract actual images. Instead, describe what you see and what the guidelines specify.

primary_colors: Hex codes (#RRGGBB) for the brand's primary palette. LOOK FOR: color swatches, "Primary colors", color specifications with hex/RGB values. Convert RGB, CMYK, or Pantone to hex approximations.
secondary_colors: Hex codes for secondary/accent palette.
fonts: All font family names referenced (exact names as written).
logo_description: Describe what the logo looks like, its variations, and any usage rules/restrictions (clear space, minimum size, forbidden modifications). Do NOT try to reproduce or extract the actual image. Return null if no logo guidelines exist.
photography_style: Description of photo direction if discussed (e.g. "candid, natural light, real people"). Describe subject matter, lighting, composition, and mood.
visual_style: Overall design direction (e.g. "clean minimalist with bold accents").
design_cues: Notable patterns, textures, graphic elements, or motifs. Describe them textually.

═══════════════════════════════════════
TYPOGRAPHY
═══════════════════════════════════════

primary_font: Heading/display font family name (exact name as written, even if commercial/proprietary).
secondary_font: Body text font family name (exact name as written, even if commercial/proprietary).
heading_style: Describe heading treatment (e.g. "ALL CAPS, heavy weight, tight tracking").
body_style: Describe body text treatment (e.g. "Regular weight, 16px/1.5 line height").
font_details: Array of font objects for ALL fonts referenced in the document. For each font, extract:
  - name: Exact font family name as written (e.g. "RBNo3.1", "Gotham", "Montserrat")
  - role: "primary" | "secondary" | "accent" | "display" | "body" | "other" — infer from context
  - styles: Array of available weights/styles mentioned (e.g. ["Light", "Book", "Bold", "Extra Bold", "Black"])
  - heading_use: How this font is used for headings, if applicable (e.g. "Bold or Extra Bold, ALL CAPS")
  - body_use: How this font is used for body text, if applicable (e.g. "Light weight, regular case")
  - usage_notes: Any additional usage guidance from the guidelines

═══════════════════════════════════════
EXTRACTION RULES
═══════════════════════════════════════

1. EXPLICIT vs IMPLICIT: If the document explicitly declares a field (labeled section), extract verbatim. If a field is clearly communicated but not labeled (e.g. the purpose is obvious from context but never titled "Mission"), synthesize a concise statement and mark it in _extraction_notes.

2. CONFIDENCE: For each top-level section (identity, personality, visual, typography), rate extraction confidence 0.0–1.0 based on how much direct evidence you found vs. inference.

3. COLORS: Must be valid 6-digit hex starting with #. Convert RGB, CMYK, or Pantone to hex approximations.

4. NULL means "not found": Use null for strings where no evidence exists. Use [] for arrays where nothing was found. Do NOT guess.

5. VISUAL ANALYSIS: Examine the visual design of the document itself — the colors used, the typography, the photography — as supporting evidence for fields that aren't explicitly declared. But describe visuals textually rather than extracting images.

6. Return ONLY valid JSON matching this structure exactly. No markdown fences, no commentary outside JSON.

{
  "identity": {
    "mission": "string or null",
    "vision": "string or null",
    "positioning": "string or null",
    "industry": "string or null",
    "target_audience": "string or null",
    "tagline": "string or null",
    "beliefs": ["string"],
    "values": ["string"]
  },
  "personality": {
    "primary_archetype": "string or null",
    "traits": ["string"],
    "tone_keywords": ["string"],
    "voice_description": "string or null",
    "brand_look": "string or null"
  },
  "visual": {
    "primary_colors": ["#hex"],
    "secondary_colors": ["#hex"],
    "fonts": ["string"],
    "logo_description": "string or null",
    "photography_style": "string or null",
    "visual_style": "string or null",
    "design_cues": ["string"]
  },
  "typography": {
    "primary_font": "string or null",
    "secondary_font": "string or null",
    "heading_style": "string or null",
    "body_style": "string or null",
    "font_details": [{"name": "string", "role": "primary|secondary|accent|display|body|other", "styles": ["string"], "heading_use": "string or null", "body_use": "string or null", "usage_notes": "string or null"}]
  },
  "section_confidence": {
    "identity": 0.0,
    "personality": 0.0,
    "visual": 0.0,
    "typography": 0.0
  },
  "_extraction_notes": ["string"]
}
PROMPT;
    }

    protected function parseResponse(string $text): array
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }

        $data = json_decode($text, true);
        if (! is_array($data)) {
            Log::error('[ClaudePdfExtractionService] Failed to parse Claude JSON response', [
                'raw_text' => mb_substr($text, 0, 500),
            ]);
            return BrandExtractionSchema::empty();
        }

        return $this->mapToSchema($data);
    }

    /**
     * Map Claude's freeform JSON into the BrandExtractionSchema shape
     * so it works seamlessly with BrandSnapshotService.
     */
    protected function mapToSchema(array $data): array
    {
        $schema = BrandExtractionSchema::empty();

        $id = $data['identity'] ?? [];
        $schema['identity']['mission'] = $this->string($id['mission'] ?? null);
        $schema['identity']['vision'] = $this->string($id['vision'] ?? null);
        $schema['identity']['positioning'] = $this->string($id['positioning'] ?? null);
        $schema['identity']['industry'] = $this->string($id['industry'] ?? null);
        $schema['identity']['target_audience'] = $this->string($id['target_audience'] ?? null);
        $schema['identity']['tagline'] = $this->string($id['tagline'] ?? null);
        $schema['identity']['beliefs'] = $this->stringArray($id['beliefs'] ?? []);
        $schema['identity']['values'] = $this->stringArray($id['values'] ?? []);

        $p = $data['personality'] ?? [];
        $schema['personality']['primary_archetype'] = $this->string($p['primary_archetype'] ?? null);
        $schema['personality']['traits'] = $this->stringArray($p['traits'] ?? []);
        $schema['personality']['tone_keywords'] = $this->stringArray($p['tone_keywords'] ?? []);
        $schema['personality']['voice_description'] = $this->string($p['voice_description'] ?? null);
        $schema['personality']['brand_look'] = $this->string($p['brand_look'] ?? null);

        $v = $data['visual'] ?? [];
        $schema['visual']['primary_colors'] = $this->filterHexColors($this->stringArray($v['primary_colors'] ?? []));
        $schema['visual']['secondary_colors'] = $this->filterHexColors($this->stringArray($v['secondary_colors'] ?? []));
        $schema['visual']['fonts'] = $this->mergeFonts($v, $data['typography'] ?? []);
        $schema['visual']['logo_description'] = $this->string($v['logo_description'] ?? null);
        $schema['visual']['photography_style'] = $this->string($v['photography_style'] ?? null);
        $schema['visual']['visual_style'] = $this->string($v['visual_style'] ?? null);
        $schema['visual']['design_cues'] = $this->stringArray($v['design_cues'] ?? []);

        $typo = $data['typography'] ?? [];
        $schema['typography'] = [
            'primary_font' => $this->string($typo['primary_font'] ?? null),
            'secondary_font' => $this->string($typo['secondary_font'] ?? null),
            'heading_style' => $this->string($typo['heading_style'] ?? null),
            'body_style' => $this->string($typo['body_style'] ?? null),
        ];

        $fontDetails = $typo['font_details'] ?? [];
        if (is_array($fontDetails) && count($fontDetails) > 0) {
            $schema['typography']['font_details'] = array_map(function ($fd) {
                return [
                    'name' => $this->string($fd['name'] ?? null),
                    'role' => $this->string($fd['role'] ?? 'other'),
                    'styles' => $this->stringArray($fd['styles'] ?? []),
                    'heading_use' => $this->string($fd['heading_use'] ?? null),
                    'body_use' => $this->string($fd['body_use'] ?? null),
                    'usage_notes' => $this->string($fd['usage_notes'] ?? null),
                ];
            }, array_filter($fontDetails, fn ($fd) => is_array($fd) && ! empty($fd['name'])));
        }

        $schema['explicit_signals'] = [
            'archetype_declared' => $schema['personality']['primary_archetype'] !== null,
            'mission_declared' => $schema['identity']['mission'] !== null,
            'positioning_declared' => $schema['identity']['positioning'] !== null,
        ];

        $schema['sources'] = [
            'pdf' => ['extracted' => true, 'method' => 'claude_single_pass'],
            'website' => [],
            'materials' => [],
        ];

        $sectionConfidence = $data['section_confidence'] ?? [];
        $schema['confidence'] = $this->computeOverallConfidence($schema, $sectionConfidence);
        $schema['section_confidence'] = [
            'identity' => (float) ($sectionConfidence['identity'] ?? 0),
            'personality' => (float) ($sectionConfidence['personality'] ?? 0),
            'visual' => (float) ($sectionConfidence['visual'] ?? 0),
            'typography' => (float) ($sectionConfidence['typography'] ?? 0),
        ];

        $schema['_extraction_notes'] = $this->stringArray($data['_extraction_notes'] ?? []);

        return $schema;
    }

    protected function mergeFonts(array $visual, array $typography): array
    {
        $fonts = $this->stringArray($visual['fonts'] ?? []);
        $primary = $this->string($typography['primary_font'] ?? null);
        $secondary = $this->string($typography['secondary_font'] ?? null);
        if ($primary !== null && ! in_array($primary, $fonts, true)) {
            array_unshift($fonts, $primary);
        }
        if ($secondary !== null && ! in_array($secondary, $fonts, true)) {
            $fonts[] = $secondary;
        }
        return $fonts;
    }

    /**
     * Blend Claude's per-section confidence with our own field-coverage metric.
     */
    protected function computeOverallConfidence(array $schema, array $sectionConfidence): float
    {
        $coverageFilled = 0;
        $coverageTotal = 0;
        foreach (['identity', 'personality', 'visual'] as $section) {
            foreach ($schema[$section] as $v) {
                $coverageTotal++;
                if ($v !== null && $v !== [] && $v !== false) {
                    $coverageFilled++;
                }
            }
        }
        $coverage = $coverageTotal > 0 ? $coverageFilled / $coverageTotal : 0.0;

        $aiConfidences = array_filter(array_map('floatval', array_values($sectionConfidence)));
        $aiAvg = count($aiConfidences) > 0 ? array_sum($aiConfidences) / count($aiConfidences) : 0.0;

        return round(($coverage * 0.4) + ($aiAvg * 0.6), 2);
    }

    protected function countExtractedFields(array $schema): int
    {
        $count = 0;
        foreach (['identity', 'personality', 'visual', 'typography'] as $section) {
            foreach ($schema[$section] ?? [] as $v) {
                if ($v !== null && $v !== [] && $v !== false) {
                    $count++;
                }
            }
        }
        return $count;
    }

    protected function filterHexColors(array $colors): array
    {
        return array_values(array_filter($colors, fn (string $c) => preg_match('/^#[0-9A-Fa-f]{6}$/', $c)));
    }

    protected function string(mixed $val): ?string
    {
        if ($val === null || $val === '') {
            return null;
        }
        return is_string($val) ? $val : (string) $val;
    }

    protected function stringArray(mixed $val): array
    {
        if (! is_array($val)) {
            return [];
        }
        return array_values(array_filter(array_map(fn ($v) => is_string($v) ? $v : null, $val)));
    }
}
