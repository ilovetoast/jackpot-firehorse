<?php

namespace App\Http\Controllers\Editor;

use App\Enums\AITaskType;
use App\Exceptions\AIBudgetExceededException;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Tenant;
use App\Services\AIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /app/api/generate-copy — inline copy assist for the asset editor (Phase 7).
 *
 * All model calls go through {@see AIService::executeAgent} so tokens and cost are tracked
 * on ai_agent_runs with tenant (and brand as entity when present), consistent with other agents.
 */
class EditorGenerateCopyController extends Controller
{
    private const SCORE_CAP = 85;

    public function __construct(
        protected AIService $aiService
    ) {}

    /**
     * @return array{score: int, feedback: string[]}
     */
    private function scoreCopy(string $text, ?array $brandContext): array
    {
        $brandContext = $brandContext ?? [];
        $tone = $brandContext['tone'] ?? [];
        if (! is_array($tone)) {
            $tone = $tone !== null && $tone !== '' ? [(string) $tone] : [];
        }
        $keywords = array_values(array_filter(array_map(static fn ($x) => strtolower(trim((string) $x)), $tone)));
        $lower = strtolower($text);
        $hits = 0;
        foreach ($keywords as $kw) {
            if ($kw !== '' && str_contains($lower, $kw)) {
                $hits++;
            }
        }
        $n = count($keywords);
        $raw = $n === 0 ? 72 : (int) (32 + (int) (53 * $hits / max(1, $n)));
        $score = (int) min(self::SCORE_CAP, $raw);
        $feedback = [];
        if ($n > 0 && $hits === 0) {
            $feedback[] = 'Weave in tone cues: '.implode(', ', array_slice($keywords, 0, 4));
        }
        if ($n > 0 && $hits > 0 && $hits < $n) {
            $feedback[] = 'Strong start — consider echoing additional tone keywords where natural.';
        }

        return ['score' => $score, 'feedback' => $feedback];
    }

    private function intentWordLimitLine(string $intent): string
    {
        return match ($intent) {
            'headline' => '6–10 words (strict; count words)',
            'caption' => '12–20 words (strict; count words)',
            default => 'one concise paragraph — flexible, but avoid rambling (aim under ~80 words unless the existing text is longer)',
        };
    }

    private function layoutHintLine(?float $textBoxWidth): string
    {
        if ($textBoxWidth === null || $textBoxWidth <= 0) {
            return 'medium frame — balance brevity with clarity';
        }
        if ($textBoxWidth < 400) {
            return 'narrow text box — prefer very short lines, punchy phrases, minimal clauses';
        }
        if ($textBoxWidth >= 640) {
            return 'wide text box — room for slightly longer phrases if still scannable';
        }

        return 'medium frame — balance brevity with clarity';
    }

    /**
     * @param  array<string, mixed>|null  $brandContext
     * @param  array<string, mixed>|null  $visualContext
     */
    private function buildPrompt(
        string $operation,
        string $intent,
        string $input,
        ?array $brandContext,
        ?array $visualContext,
        ?string $toneOverride,
        ?float $textBoxWidth
    ): string {
        $brandContext = $brandContext ?? [];
        $tone = $brandContext['tone'] ?? [];
        if (! is_array($tone)) {
            $tone = $tone !== null && $tone !== '' ? [(string) $tone] : [];
        }
        $toneStr = $tone !== [] ? implode(', ', array_map('strval', $tone)) : 'balanced, clear';
        $archetype = is_string($brandContext['archetype'] ?? null) ? $brandContext['archetype'] : '';
        $visualStyle = is_string($brandContext['visual_style'] ?? null) ? $brandContext['visual_style'] : '';
        $voiceField = is_string($brandContext['voice'] ?? null) ? trim((string) $brandContext['voice']) : '';
        $voiceLine = $voiceField !== '' ? $voiceField : trim(implode(', ', array_filter([$visualStyle, $archetype ? "archetype: {$archetype}" : ''])));

        $visBlock = 'Not specified.';
        if (is_array($visualContext) && $visualContext !== []) {
            $hp = ! empty($visualContext['has_product']) ? 'yes' : 'no';
            $st = is_string($visualContext['style'] ?? null) ? $visualContext['style'] : '';
            $co = is_string($visualContext['color'] ?? null) ? $visualContext['color'] : '';
            $comp = is_string($visualContext['composition_type'] ?? null) ? $visualContext['composition_type'] : '';
            $ctr = is_string($visualContext['contrast_level'] ?? null) ? $visualContext['contrast_level'] : '';
            $visBlock = "product imagery present: {$hp}; scene/style: {$st}; palette/mood: {$co}; composition: {$comp}; contrast: {$ctr}";
        }

        $limitWords = $this->intentWordLimitLine($intent);
        $layoutHint = $this->layoutHintLine($textBoxWidth);
        $wLabel = $textBoxWidth !== null && $textBoxWidth > 0 ? (int) round($textBoxWidth).'px' : 'unknown';

        $inputTrim = trim($input);
        $existing = $inputTrim !== '' ? $inputTrim : '(none — write fresh copy)';

        $task = match ($operation) {
            'improve' => 'Improve and tighten the existing text. Preserve intent; make it clearer and more compelling.',
            'shorten' => 'Shorten the existing text while keeping the core message.',
            'premium' => 'Rewrite with a more premium, elevated voice. Avoid clichés.',
            'align_tone' => 'Rewrite to align tightly with the brand tone listed below.',
            default => 'Write fresh marketing copy for this context.',
        };

        $toneLine = $toneOverride ? "Tone override: {$toneOverride}" : "Tone: {$toneStr}";

        return <<<PROMPT
{$task}

Write a concise marketing {$intent}.

Brand:
- {$toneLine}
- Style: {$visualStyle}
- Archetype: {$archetype}
- Voice (combined): {$voiceLine}

Context:
- Visual: {$visBlock}
- Text box width: ~{$wLabel} — {$layoutHint}
- Existing text: {$existing}

Instructions:
- Length: {$limitWords}
- Avoid generic filler ("innovative", "world-class", "leading") unless justified.
- Be specific and brand-aligned.

Alternates (required): After the primary "text", provide exactly 3 alternate lines that are DISTINCT in voice:
(1) Bold / punchy — confident, energetic, short clauses
(2) Minimal / clean — restrained, premium, little adornment
(3) Emotional / narrative — human, story-led, still on-brand

Return ONLY valid JSON (no markdown) with this exact shape:
{"text":"primary line or paragraph here","alternates":[{"label":"Bold / punchy","text":"..."},{"label":"Minimal / clean","text":"..."},{"label":"Emotional / narrative","text":"..."}]}
Rules: "alternates" must have exactly 3 objects with keys "label" and "text" only. Labels must match the three voices above.
PROMPT;
    }

    /**
     * @return array{text: string, suggestions: list<array{label: string, text: string}>, copy_score: array{score: int, feedback: string[]}}
     */
    private function stubResponse(string $operation, string $intent, string $input, ?array $brandContext): array
    {
        $base = $input !== '' ? mb_substr($input, 0, 80).(mb_strlen($input) > 80 ? '…' : '') : 'Fresh '.$intent.' copy';
        $text = match ($operation) {
            'shorten' => mb_substr($base, 0, min(48, mb_strlen($base))),
            'premium' => 'Elevated '.$intent.': '.$base,
            'improve' => 'Refined: '.$base,
            'align_tone' => 'On-brand: '.$base,
            default => 'Your new '.$intent.': '.$base,
        };
        $suggestions = [
            ['label' => 'Bold / punchy', 'text' => 'Stub — '.$intent.' with punch.'],
            ['label' => 'Minimal / clean', 'text' => 'Stub — quiet '.$intent.'.'],
            ['label' => 'Emotional / narrative', 'text' => 'Stub — story-led '.$intent.'.'],
        ];
        $copyScore = $this->scoreCopy($text, $brandContext);
        $copyScore['feedback'][] = 'Connect OPENAI_API_KEY for live AI copy (stub response).';

        return [
            'text' => $text,
            'suggestions' => $suggestions,
            'copy_score' => $copyScore,
        ];
    }

    /**
     * @return list<array{label: string, text: string}>
     */
    private function parseAlternates(mixed $alt): array
    {
        if (! is_array($alt)) {
            return [];
        }
        $expectedLabels = ['Bold / punchy', 'Minimal / clean', 'Emotional / narrative'];
        $out = [];
        $i = 0;
        foreach ($alt as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = ['label' => $expectedLabels[$i] ?? 'Alternate', 'text' => trim($item)];
                $i++;

                continue;
            }
            if (is_array($item) && isset($item['text']) && is_string($item['text']) && trim($item['text']) !== '') {
                $label = is_string($item['label'] ?? null) ? trim($item['label']) : ($expectedLabels[$i] ?? 'Alternate');
                $out[] = ['label' => $label !== '' ? $label : ($expectedLabels[$i] ?? 'Alternate'), 'text' => trim($item['text'])];
                $i++;
            }
        }

        return $out;
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'input' => 'nullable|string|max:8000',
            'intent' => 'required|string|in:headline,body,caption',
            'operation' => 'required|string|in:generate,improve,shorten,premium,align_tone',
            'brand_context' => 'nullable|array',
            'visual_context' => 'nullable|array',
            'tone_override' => 'nullable|string|max:200',
            'text_box_width' => 'nullable|numeric|min:1|max:10000',
            /** When set, {@see AIService::extractEntityFromOptions} attributes the run to this composition. */
            'composition_id' => 'nullable|integer|min:1',
        ]);

        $input = (string) ($validated['input'] ?? '');
        $intent = $validated['intent'];
        $operation = $validated['operation'];
        /** @var array<string, mixed>|null $brandContext */
        $brandContext = $validated['brand_context'] ?? null;
        /** @var array<string, mixed>|null $visualContext */
        $visualContext = $validated['visual_context'] ?? null;
        $toneOverride = $validated['tone_override'] ?? null;
        $textBoxWidth = isset($validated['text_box_width']) ? (float) $validated['text_box_width'] : null;

        if ($operation !== 'generate' && trim($input) === '') {
            return response()->json(['message' => 'Existing text is required for this action.'], 422);
        }

        if (empty(config('ai.openai.api_key'))) {
            return response()->json($this->stubResponse($operation, $intent, $input, $brandContext));
        }

        $tenant = app('tenant');
        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => 'Tenant context required for AI copy assist.'], 403);
        }

        $brand = app('brand');

        $prompt = $this->buildPrompt($operation, $intent, $input, $brandContext, $visualContext, $toneOverride, $textBoxWidth);

        $executeOptions = [
            'tenant' => $tenant,
            'user' => $user,
            'max_tokens' => 650,
            'temperature' => 0.78,
            'response_format' => ['type' => 'json_object'],
        ];
        if ($brand instanceof Brand) {
            $executeOptions['brand_id'] = $brand->id;
        }
        $compositionId = $validated['composition_id'] ?? null;
        if ($compositionId !== null) {
            $executeOptions['composition_id'] = (string) $compositionId;
        }

        try {
            $result = $this->aiService->executeAgent(
                'editor_copy_assistant',
                AITaskType::EDITOR_COPY_ASSIST,
                $prompt,
                $executeOptions
            );
            $raw = trim($result['text'] ?? '');
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);
            $parsed = json_decode($raw, true);
            $text = '';
            if (is_array($parsed)) {
                $text = trim((string) ($parsed['text'] ?? ''));
            }
            if ($text === '') {
                return response()->json([
                    'message' => 'Could not generate copy. Try again.',
                ], 502);
            }
            $alternates = [];
            if (is_array($parsed)) {
                $alternates = $this->parseAlternates($parsed['alternates'] ?? $parsed['suggestions'] ?? []);
            }
            $expected = ['Bold / punchy', 'Minimal / clean', 'Emotional / narrative'];
            $suggestions = [];
            foreach ($expected as $i => $label) {
                $found = $alternates[$i] ?? null;
                $t = is_array($found) ? trim((string) ($found['text'] ?? '')) : '';
                if ($t !== '') {
                    $suggestions[] = [
                        'label' => is_array($found) && is_string($found['label'] ?? null) && trim((string) $found['label']) !== ''
                            ? trim((string) $found['label'])
                            : $label,
                        'text' => $t,
                    ];

                    continue;
                }
                $suggestions[] = [
                    'label' => $label,
                    'text' => mb_substr($text, 0, min(160, mb_strlen($text))).(mb_strlen($text) > 160 ? '…' : ''),
                ];
            }
            $suggestions = array_slice($suggestions, 0, 3);
            $copyScore = $this->scoreCopy($text, $brandContext);

            Log::info('editor.generate_copy', [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'brand_id' => $brand instanceof Brand ? $brand->id : null,
                'operation' => $operation,
                'intent' => $intent,
                'agent_run_id' => $result['agent_run_id'] ?? null,
                'tokens_in' => $result['tokens_in'] ?? null,
                'tokens_out' => $result['tokens_out'] ?? null,
            ]);

            return response()->json([
                'text' => $text,
                'suggestions' => $suggestions,
                'copy_score' => $copyScore,
            ]);
        } catch (AIBudgetExceededException $e) {
            Log::warning('editor.generate_copy_budget', [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getPublicMessage(),
            ], 503);
        } catch (\Throwable $e) {
            Log::warning('editor.generate_copy_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Could not generate copy. Try again.',
            ], 503);
        }
    }
}
