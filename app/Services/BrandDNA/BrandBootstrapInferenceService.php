<?php

namespace App\Services\BrandDNA;

use App\Enums\AITaskType;
use App\Models\BrandBootstrapRun;
use App\Models\Tenant;
use App\Services\AIService;
use Illuminate\Support\Facades\Log;

/**
 * Brand Bootstrap Inference Service — AI inference from scraped data.
 * Uses existing AI agent infrastructure. Outputs strict JSON matching BrandModelVersion schema.
 */
class BrandBootstrapInferenceService
{
    public function __construct(
        private AIService $aiService
    ) {}

    /**
     * Run AI inference on a completed bootstrap run.
     * Supports both legacy (raw at top level) and Phase 7 (normalized + ai_signals) payloads.
     *
     * @return array{ai_response_json: array, tokens_in: int, tokens_out: int, cost: float}
     */
    public function infer(BrandBootstrapRun $run, Tenant $tenant): array
    {
        $raw = $run->raw_payload ?? [];
        if (isset($raw['error'])) {
            throw new \InvalidArgumentException('Run has scrape error: ' . ($raw['error'] ?? 'unknown'));
        }

        $normalized = $raw['normalized'] ?? null;
        $aiSignals = $raw['ai_signals'] ?? null;
        $prompt = $normalized && $aiSignals
            ? $this->buildPromptFromNormalized($normalized, $aiSignals, $run->source_url ?? '')
            : $this->buildPrompt($raw, $run->source_url ?? '');

        $result = $this->aiService->executeAgent(
            'brand_bootstrap_inference',
            AITaskType::BRAND_BOOTSTRAP_INFERENCE,
            $prompt,
            [
                'tenant' => $tenant,
                'temperature' => 0.4,
                'max_tokens' => 4000,
                'brand_bootstrap_run_id' => $run->id,
            ]
        );

        $text = trim($result['text'] ?? '');
        $json = $this->parseJsonResponse($text);

        return [
            'ai_response_json' => $json,
            'tokens_in' => $result['tokens_in'] ?? 0,
            'tokens_out' => $result['tokens_out'] ?? 0,
            'cost' => $result['cost'] ?? 0.0,
        ];
    }

    protected function buildPrompt(array $raw, string $sourceUrl): string
    {
        $domain = parse_url($sourceUrl, PHP_URL_HOST) ?? '';

        $meta = $raw['meta'] ?? [];
        $headlines = $raw['headlines'] ?? [];
        $colors = $raw['colors_detected'] ?? [];
        $nav = $raw['navigation'] ?? [];
        $branding = $raw['branding'] ?? [];

        $context = [
            'domain' => $domain,
            'meta' => [
                'title' => $meta['title'] ?? '',
                'description' => $meta['description'] ?? '',
                'og_title' => $meta['og_title'] ?? '',
                'og_image' => $meta['og_image'] ?? '',
            ],
            'headlines' => $headlines,
            'colors_detected' => $colors,
            'navigation_labels' => array_column($nav['links'] ?? [], 'label'),
            'logo_candidates' => $branding['logo_candidates'] ?? [],
        ];

        $schema = <<<'SCHEMA'
{
  "identity": {
    "tagline": "",
    "mission": "",
    "positioning": "",
    "industry": "",
    "target_audience": ""
  },
  "personality": {
    "archetype": "",
    "traits": [],
    "tone": "",
    "voice": ""
  },
  "visual": {
    "style": "",
    "composition": "",
    "color_temperature": ""
  },
  "typography": {
    "primary_font_style": "",
    "secondary_font_style": "",
    "font_mood": ""
  },
  "scoring_rules": {
    "allowed_color_palette": [],
    "allowed_fonts": [],
    "banned_colors": [],
    "tone_keywords": [],
    "banned_keywords": [],
    "photography_attributes": []
  },
  "scoring_config": {
    "color_weight": 30,
    "typography_weight": 20,
    "tone_weight": 30,
    "imagery_weight": 20
  }
}
SCHEMA;

        $system = 'You are a senior brand strategist. Return ONLY valid JSON. No commentary. No markdown. Match the schema exactly.';
        $user = "Scraped website data:\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            . "\n\nRequired JSON schema (output exactly this structure with inferred values):\n" . $schema;

        return $system . "\n\n---\n\n" . $user;
    }

    /**
     * Build prompt from Phase 7 normalized + ai_signals.
     */
    protected function buildPromptFromNormalized(array $normalized, array $aiSignals, string $sourceUrl): string
    {
        $domain = parse_url($sourceUrl, PHP_URL_HOST) ?? '';
        $context = [
            'domain' => $domain,
            'normalized_signals' => $normalized,
            'ai_extracted_signals' => $aiSignals,
        ];

        $schema = <<<'SCHEMA'
{
  "identity": {
    "tagline": "",
    "mission": "",
    "positioning": "",
    "industry": "",
    "target_audience": ""
  },
  "personality": {
    "archetype": "",
    "traits": [],
    "tone": "",
    "voice": ""
  },
  "visual": {
    "style": "",
    "composition": "",
    "color_temperature": ""
  },
  "typography": {
    "primary_font_style": "",
    "secondary_font_style": "",
    "font_mood": ""
  },
  "scoring_rules": {
    "allowed_color_palette": [],
    "allowed_fonts": [],
    "banned_colors": [],
    "tone_keywords": [],
    "banned_keywords": [],
    "photography_attributes": []
  },
  "scoring_config": {
    "color_weight": 30,
    "typography_weight": 20,
    "tone_weight": 30,
    "imagery_weight": 20
  }
}
SCHEMA;

        $system = 'You are a senior brand strategist. Synthesize the normalized signals and AI-extracted signals into a complete Brand DNA. Return ONLY valid JSON. No commentary. No markdown. Match the schema exactly.';
        $user = "Context:\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            . "\n\nRequired JSON schema (output exactly this structure with synthesized values):\n" . $schema;

        return $system . "\n\n---\n\n" . $user;
    }

    protected function parseJsonResponse(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('AI returned empty response');
        }

        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/i', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('[BrandBootstrapInferenceService] Invalid JSON from AI', [
                'preview' => substr($text, 0, 500),
                'error' => json_last_error_msg(),
            ]);
            throw new \RuntimeException('AI returned invalid JSON: ' . json_last_error_msg());
        }

        return $this->ensureSchema($decoded);
    }

    protected function ensureSchema(array $data): array
    {
        $default = [
            'identity' => ['tagline' => '', 'mission' => '', 'positioning' => '', 'industry' => '', 'target_audience' => ''],
            'personality' => ['archetype' => '', 'traits' => [], 'tone' => '', 'voice' => ''],
            'visual' => ['style' => '', 'composition' => '', 'color_temperature' => ''],
            'typography' => ['primary_font_style' => '', 'secondary_font_style' => '', 'font_mood' => ''],
            'scoring_rules' => [
                'allowed_color_palette' => [],
                'allowed_fonts' => [],
                'banned_colors' => [],
                'tone_keywords' => [],
                'banned_keywords' => [],
                'photography_attributes' => [],
            ],
            'scoring_config' => [
                'color_weight' => 30,
                'typography_weight' => 20,
                'tone_weight' => 30,
                'imagery_weight' => 20,
            ],
        ];

        foreach ($default as $key => $val) {
            if (! isset($data[$key])) {
                $data[$key] = $val;
            } elseif (is_array($val) && is_array($data[$key])) {
                $data[$key] = array_merge($val, $data[$key]);
            }
        }

        return $data;
    }
}
