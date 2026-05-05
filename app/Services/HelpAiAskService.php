<?php

namespace App\Services;

use App\Enums\AITaskType;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2: grounded AI answers for in-app help (retrieved help_actions only).
 *
 * Uses {@see AIService} with agent {@see config('ai.help_ask.agent_id')} and gpt-4o-mini.
 */
class HelpAiAskService
{
    public function __construct(
        protected HelpActionService $helpActionService,
        protected AIService $aiService,
    ) {}

    /**
     * @param  list<string>  $userPermissions
     * @return array<string, mixed>
     */
    public function ask(
        string $question,
        array $userPermissions,
        ?Brand $brand,
        Tenant $tenant,
        User $user,
    ): array {
        $rank = $this->helpActionService->rankForNaturalLanguageQuestion(
            $question,
            $userPermissions,
            $brand,
            (int) config('ai.help_ask.max_actions_for_prompt', 3)
        );
        $bestScore = $rank['best_score'];
        $matchedKeys = $rank['matched_keys'];
        $matches = $rank['serialized'];
        $visible = $this->helpActionService->visibleActions($userPermissions);
        $common = $this->helpActionService->pickCommon($visible);
        $commonOut = array_map(fn (array $a) => $this->helpActionService->serializeAction($a, $brand, $visible), $common);
        $commonSlice = array_slice($commonOut, 0, 8);

        $strongMin = (int) config('ai.help_ask.strong_match_min_score', 12);

        $baseLog = [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand?->id,
            'query' => mb_substr(trim($question), 0, 2000),
            'matched_action_keys' => $matchedKeys,
            'best_score' => $bestScore,
        ];

        if (($tenant->settings['ai_enabled'] ?? true) === false) {
            Log::info('help.ask.ai_disabled_tenant', $baseLog);

            return [
                'kind' => 'ai_disabled',
                'matched_keys' => $matchedKeys,
                'best_score' => $bestScore,
                'message' => 'AI-assisted answers are turned off for this workspace.',
                'suggested' => $commonSlice,
                'usage' => null,
            ];
        }

        if (! config('ai.help_ask.enabled', true)) {
            Log::info('help.ask.feature_disabled', $baseLog);

            return [
                'kind' => 'ai_disabled',
                'matched_keys' => $matchedKeys,
                'best_score' => $bestScore,
                'message' => 'AI help is temporarily unavailable.',
                'suggested' => $commonSlice,
                'usage' => null,
            ];
        }

        if ($matches === [] || $bestScore < $strongMin) {
            Log::info('help.ask.no_strong_match', $baseLog);

            return [
                'kind' => 'fallback',
                'matched_keys' => $matchedKeys,
                'best_score' => $bestScore,
                'message' => 'No close documented topic was found for that question. Try different keywords or browse common topics below.',
                'suggested' => $commonSlice,
                'usage' => null,
            ];
        }

        $allowedKeys = array_fill_keys($matchedKeys, true);
        $contextJson = json_encode(array_values($matches), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $prompt = $this->buildPrompt(trim($question), $contextJson);

        $agentId = (string) config('ai.help_ask.agent_id', 'in_app_help_assistant');

        try {
            $ai = $this->aiService->executeAgent(
                $agentId,
                AITaskType::IN_APP_HELP_ACTION_ANSWER,
                $prompt,
                [
                    'tenant' => $tenant,
                    'user' => $user,
                    'brand_id' => $brand?->id,
                    'triggering_context' => 'user',
                    'max_tokens' => 900,
                    'temperature' => 0.2,
                ]
            );

            $parsed = $this->parseModelJson((string) ($ai['text'] ?? ''));
            $allowedForSanitize = $allowedKeys;
            foreach ($matches as $row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach ($row['related'] ?? [] as $rel) {
                    if (is_array($rel) && ! empty($rel['key']) && is_string($rel['key'])) {
                        $allowedForSanitize[$rel['key']] = true;
                    }
                }
            }
            $sanitized = $this->sanitizeAiPayload($parsed, $matches, $allowedForSanitize);

            Log::info('help.ask.success', $baseLog + [
                'model' => $ai['model'] ?? null,
                'agent_run_id' => $ai['agent_run_id'] ?? null,
                'tokens_in' => $ai['tokens_in'] ?? null,
                'tokens_out' => $ai['tokens_out'] ?? null,
                'cost' => $ai['cost'] ?? null,
            ]);

            return [
                'kind' => 'ai',
                'matched_keys' => $matchedKeys,
                'best_score' => $bestScore,
                'answer' => $sanitized,
                'usage' => [
                    'agent_run_id' => $ai['agent_run_id'] ?? null,
                    'model' => $ai['model'] ?? null,
                    'tokens_in' => $ai['tokens_in'] ?? null,
                    'tokens_out' => $ai['tokens_out'] ?? null,
                    'cost' => $ai['cost'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('help.ask.ai_failed', $baseLog + [
                'error' => $e->getMessage(),
            ]);

            $primary = $matches[0] ?? null;

            return [
                'kind' => 'fallback_action',
                'matched_keys' => $matchedKeys,
                'best_score' => $bestScore,
                'message' => 'We could not generate an AI summary right now. Here is the closest documented topic.',
                'primary' => $primary,
                'suggested' => $commonSlice,
                'usage' => null,
            ];
        }
    }

    private function buildPrompt(string $userQuestion, string $helpActionsJson): string
    {
        return <<<PROMPT
You are Jackpot in-app help. You MUST answer ONLY using the JSON array HELP_ACTIONS below. Treat it as the only source of truth.

Hard rules:
- Do NOT invent routes, URLs, permissions, screenshots, features, or workflows that are not clearly supported by HELP_ACTIONS.
- Do NOT reference pages or actions that are not present in HELP_ACTIONS (use only "key", "title", "url", "route_name", "related", "steps", "short_answer" from the payload).
- If the user question is not answerable from HELP_ACTIONS, set confidence_tier to "low", set direct_answer to explain that no exact documented match was found in the provided topics, and still only refer to keys/titles that exist in HELP_ACTIONS.
- recommended_page must be either null or an object copied from one entry in HELP_ACTIONS with keys: key, title, url (use the exact "url" string from that entry, or null if that entry has url null).
- related_actions must be an array of { "key", "title" } taken only from HELP_ACTIONS entries or their "related" arrays (same key/title as in payload).

Output format: reply with a single JSON object and nothing else (no markdown fences). Required keys:
- "severity": always the string "info"
- "confidence": number from 0 to 1 (model confidence in the grounded answer)
- "summary": one short line for logs (max 200 chars)
- "direct_answer": string
- "numbered_steps": array of strings (use HELP_ACTIONS steps when applicable; may rephrase but must not add new steps that contradict the payload)
- "recommended_page": null or { "key", "title", "url" }
- "related_actions": array of { "key", "title" }
- "confidence_tier": one of "high", "medium", "low"

USER_QUESTION:
{$userQuestion}

HELP_ACTIONS:
{$helpActionsJson}
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseModelJson(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return [];
        }
        $slice = substr($text, $start, $end - $start + 1);
        try {
            $decoded = json_decode($slice, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<array<string, mixed>>  $matches
     * @param  array<string, true>  $allowedKeys
     * @return array<string, mixed>
     */
    private function sanitizeAiPayload(array $parsed, array $matches, array $allowedKeys): array
    {
        $urlByKey = [];
        $titleByKey = [];
        foreach ($matches as $row) {
            if (! is_array($row) || empty($row['key'])) {
                continue;
            }
            $k = (string) $row['key'];
            $urlByKey[$k] = isset($row['url']) && is_string($row['url']) ? $row['url'] : null;
            $titleByKey[$k] = isset($row['title']) && is_string($row['title']) ? $row['title'] : '';
        }
        foreach ($matches as $row) {
            if (! is_array($row) || empty($row['key'])) {
                continue;
            }
            foreach ($row['related'] ?? [] as $rel) {
                if (! is_array($rel) || empty($rel['key'])) {
                    continue;
                }
                $rk = (string) $rel['key'];
                if (! isset($titleByKey[$rk]) && isset($rel['title'])) {
                    $titleByKey[$rk] = is_string($rel['title']) ? $rel['title'] : '';
                }
                if (! array_key_exists($rk, $urlByKey) && array_key_exists('url', $rel)) {
                    $urlByKey[$rk] = is_string($rel['url']) ? $rel['url'] : null;
                }
            }
        }

        $direct = isset($parsed['direct_answer']) && is_string($parsed['direct_answer']) ? trim($parsed['direct_answer']) : '';
        $steps = [];
        if (isset($parsed['numbered_steps']) && is_array($parsed['numbered_steps'])) {
            foreach ($parsed['numbered_steps'] as $s) {
                if (is_string($s) && trim($s) !== '') {
                    $steps[] = trim($s);
                } elseif (is_scalar($s) && trim((string) $s) !== '') {
                    $steps[] = trim((string) $s);
                }
                if (count($steps) >= 12) {
                    break;
                }
            }
        }

        $rec = null;
        if (isset($parsed['recommended_page']) && is_array($parsed['recommended_page'])) {
            $rk = $parsed['recommended_page']['key'] ?? null;
            if (is_string($rk) && isset($allowedKeys[$rk])) {
                $rec = [
                    'key' => $rk,
                    'title' => $titleByKey[$rk] ?? (is_string($parsed['recommended_page']['title'] ?? null) ? $parsed['recommended_page']['title'] : ''),
                    'url' => $urlByKey[$rk] ?? null,
                ];
            }
        }

        $relatedOut = [];
        if (isset($parsed['related_actions']) && is_array($parsed['related_actions'])) {
            foreach ($parsed['related_actions'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = $item['key'] ?? null;
                if (! is_string($id) || ! isset($titleByKey[$id])) {
                    continue;
                }
                $relatedOut[] = [
                    'key' => $id,
                    'title' => $titleByKey[$id] !== '' ? $titleByKey[$id] : (is_string($item['title'] ?? null) ? $item['title'] : $id),
                ];
                if (count($relatedOut) >= 8) {
                    break;
                }
            }
        }

        $tier = $parsed['confidence_tier'] ?? 'low';
        if (! is_string($tier) || ! in_array(strtolower($tier), ['high', 'medium', 'low'], true)) {
            $tier = 'low';
        } else {
            $tier = strtolower($tier);
        }

        if ($direct === '') {
            $direct = 'No grounded answer could be parsed from the model response.';
        }

        return [
            'direct_answer' => $direct,
            'numbered_steps' => $steps,
            'recommended_page' => $rec,
            'related_actions' => $relatedOut,
            'confidence' => $tier,
        ];
    }
}
