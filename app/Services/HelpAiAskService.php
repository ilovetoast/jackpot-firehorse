<?php

namespace App\Services;

use App\Enums\AITaskType;
use App\Models\Brand;
use App\Models\HelpAiQuestion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2: grounded AI answers for in-app help (retrieved help_actions + tenant WORKSPACE_FACTS).
 *
 * Uses {@see AIService} with agent {@see config('ai.help_ask.agent_id')} and gpt-4o-mini.
 * Persists each ask to {@see HelpAiQuestion} for admin diagnostics.
 */
class HelpAiAskService
{
    public function __construct(
        protected HelpActionService $helpActionService,
        protected AIService $aiService,
        protected PlanService $planService,
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
        $ctx = new HelpActionVisibilityContext($user, $tenant, $brand);
        $visible = $this->helpActionService->visibleActions($userPermissions, $ctx);
        $common = $this->helpActionService->pickCommon($visible);
        $commonOut = array_map(fn (array $a) => $this->helpActionService->serializeAction($a, $brand, $visible), $common);
        $commonSlice = array_slice($commonOut, 0, 8);

        $strongMin = (int) config('ai.help_ask.strong_match_min_score', 12);

        $baseLog = [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'brand_id' => $brand?->id,
            'query' => mb_substr(trim($question), 0, 2000),
            'matched_action_keys' => [],
            'best_score' => 0,
        ];

        if (($tenant->settings['ai_enabled'] ?? true) === false) {
            Log::info('help.ask.ai_disabled_tenant', $baseLog);

            $payload = [
                'kind' => 'ai_disabled',
                'matched_keys' => [],
                'best_score' => 0,
                'message' => 'AI-assisted answers are turned off for this workspace.',
                'suggested' => $commonSlice,
                'usage' => null,
            ];

            return $this->attachPersistedId($payload, $question, $tenant, $user, $brand, 'ai_disabled', [], 0, null, null, null);
        }

        if (! config('ai.help_ask.enabled', true)) {
            Log::info('help.ask.feature_disabled', $baseLog);

            $payload = [
                'kind' => 'feature_disabled',
                'matched_keys' => [],
                'best_score' => 0,
                'message' => 'AI help is temporarily unavailable.',
                'suggested' => $commonSlice,
                'usage' => null,
            ];

            return $this->attachPersistedId($payload, $question, $tenant, $user, $brand, 'feature_disabled', [], 0, null, null, null);
        }

        $blocked = $this->blockedForUnavailableProductEntitlements(trim($question), $tenant, $user, $brand);
        if ($blocked !== null) {
            Log::info('help.ask.feature_unavailable', $baseLog + ['feature' => $blocked['feature']]);

            $payload = [
                'kind' => 'feature_unavailable',
                'feature' => $blocked['feature'],
                'matched_keys' => [],
                'best_score' => 0,
                'message' => $blocked['message'],
                'suggested' => $commonSlice,
                'usage' => null,
            ];

            return $this->attachPersistedId($payload, $question, $tenant, $user, $brand, 'feature_unavailable', [], 0, null, null, null);
        }

        $rank = $this->helpActionService->rankForNaturalLanguageQuestion(
            $question,
            $userPermissions,
            $brand,
            (int) config('ai.help_ask.max_actions_for_prompt', 3),
            null,
            null,
            $ctx
        );
        $bestScore = $rank['best_score'];
        $matchedKeys = $rank['matched_keys'];
        $matches = $rank['serialized'];

        $baseLog['matched_action_keys'] = $matchedKeys;
        $baseLog['best_score'] = $bestScore;

        if ($matches === [] || $bestScore < $strongMin) {
            Log::info('help.ask.no_strong_match', $baseLog);

            $payload = [
                'kind' => 'fallback',
                'matched_keys' => $matchedKeys,
                'best_score' => $bestScore,
                'message' => 'No close documented topic was found for that question. Try different keywords or browse common topics below.',
                'suggested' => $commonSlice,
                'usage' => null,
            ];

            return $this->attachPersistedId($payload, $question, $tenant, $user, $brand, 'no_strong_match', $matchedKeys, $bestScore, null, null, null);
        }

        $allowedKeys = array_fill_keys($matchedKeys, true);

        try {
            $contextJson = json_encode(array_values($matches), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $workspaceFacts = $this->buildWorkspaceFacts($tenant);
            $workspaceFactsJson = json_encode($workspaceFacts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $prompt = $this->buildPrompt(trim($question), $contextJson, $workspaceFactsJson);
            $agentId = (string) config('ai.help_ask.agent_id', 'in_app_help_assistant');

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

            $usage = [
                'agent_run_id' => $ai['agent_run_id'] ?? null,
                'model' => $ai['model'] ?? null,
                'tokens_in' => $ai['tokens_in'] ?? null,
                'tokens_out' => $ai['tokens_out'] ?? null,
                'cost' => $ai['cost'] ?? null,
            ];

            Log::info('help.ask.success', $baseLog + [
                'model' => $usage['model'],
                'agent_run_id' => $usage['agent_run_id'],
                'tokens_in' => $usage['tokens_in'],
                'tokens_out' => $usage['tokens_out'],
                'cost' => $usage['cost'],
            ]);

            $recommendedKey = null;
            if (isset($sanitized['recommended_page']['key']) && is_string($sanitized['recommended_page']['key'])) {
                $recommendedKey = $sanitized['recommended_page']['key'];
            }

            $confidenceTier = isset($sanitized['confidence']) && is_string($sanitized['confidence'])
                ? $sanitized['confidence']
                : null;

            $payload = [
                'kind' => 'ai',
                'matched_keys' => $matchedKeys,
                'best_score' => $bestScore,
                'answer' => $sanitized,
                'usage' => $usage,
            ];

            return $this->attachPersistedId(
                $payload,
                $question,
                $tenant,
                $user,
                $brand,
                'ai',
                $matchedKeys,
                $bestScore,
                $confidenceTier,
                $recommendedKey,
                $usage
            );
        } catch (\JsonException $e) {
            Log::warning('help.ask.context_encode_failed', $baseLog + ['error' => $e->getMessage()]);

            return $this->attachPersistedId(
                [
                    'kind' => 'fallback',
                    'matched_keys' => $matchedKeys,
                    'best_score' => $bestScore,
                    'message' => 'Could not prepare the help context for AI. Try again or browse topics below.',
                    'suggested' => $commonSlice,
                    'usage' => null,
                ],
                $question,
                $tenant,
                $user,
                $brand,
                'context_encode_failed',
                $matchedKeys,
                $bestScore,
                null,
                null,
                null
            );
        } catch (\Throwable $e) {
            Log::warning('help.ask.ai_failed', $baseLog + [
                'error' => $e->getMessage(),
            ]);

            $primary = $matches[0] ?? null;
            $recommendedKey = is_array($primary) && ! empty($primary['key']) && is_string($primary['key'])
                ? $primary['key']
                : null;

            $payload = [
                'kind' => 'fallback_action',
                'matched_keys' => $matchedKeys,
                'best_score' => $bestScore,
                'message' => 'We could not generate an AI summary right now. Here is the closest documented topic.',
                'primary' => $primary,
                'suggested' => $commonSlice,
                'usage' => null,
            ];

            return $this->attachPersistedId(
                $payload,
                $question,
                $tenant,
                $user,
                $brand,
                'ai_failed',
                $matchedKeys,
                $bestScore,
                null,
                $recommendedKey,
                null
            );
        }
    }

    /**
     * When the question clearly targets a product area that is off for this workspace, block before ranking
     * so hidden help actions are never sent to the model.
     *
     * @return array{feature: string, message: string}|null
     */
    private function blockedForUnavailableProductEntitlements(string $question, Tenant $tenant, User $user, ?Brand $brand): ?array
    {
        if ($question === '') {
            return null;
        }
        $q = mb_strtolower($question);

        if (preg_match('/\b(studio|generative|composition editor|creative beta)\b/u', $q)
            && ! $this->helpActionService->isUserFacingFeatureEnabled('generative', $user, $tenant, $brand)) {
            return [
                'feature' => 'studio',
                'message' => 'Studio does not appear to be available in this workspace. Ask an admin if you think you should have access.',
            ];
        }

        if (preg_match('/\b(creator module|prostaff|creators tab|external creator)\b/u', $q)
            && ! $this->helpActionService->isUserFacingFeatureEnabled('creator_module', $user, $tenant, $brand)) {
            return [
                'feature' => 'creators',
                'message' => 'The Creator / contributor module does not appear to be enabled for this workspace. Ask a company admin if you need it.',
            ];
        }

        return null;
    }

    /**
     * @param  list<string>  $matchedKeys
     * @param  array<string, mixed>|null  $usage
     * @return array<string, mixed>
     */
    private function attachPersistedId(
        array $payload,
        string $question,
        Tenant $tenant,
        User $user,
        ?Brand $brand,
        string $responseKind,
        array $matchedKeys,
        int $bestScore,
        ?string $confidence,
        ?string $recommendedKey,
        ?array $usage,
    ): array {
        try {
            $row = HelpAiQuestion::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'brand_id' => $brand?->id,
                'question' => mb_substr(trim($question), 0, 2000),
                'response_kind' => $responseKind,
                'matched_action_keys' => $matchedKeys,
                'best_score' => $bestScore,
                'confidence' => $confidence,
                'recommended_action_key' => $recommendedKey,
                'agent_run_id' => $usage !== null && isset($usage['agent_run_id']) && $usage['agent_run_id'] !== null
                    ? (int) $usage['agent_run_id']
                    : null,
                'cost' => $usage !== null && array_key_exists('cost', $usage) && $usage['cost'] !== null
                    ? (string) $usage['cost']
                    : null,
                'tokens_in' => $usage !== null && isset($usage['tokens_in']) && $usage['tokens_in'] !== null
                    ? (int) $usage['tokens_in']
                    : null,
                'tokens_out' => $usage !== null && isset($usage['tokens_out']) && $usage['tokens_out'] !== null
                    ? (int) $usage['tokens_out']
                    : null,
            ]);

            return array_merge($payload, ['help_ai_question_id' => $row->id]);
        } catch (\Throwable $e) {
            Log::warning('help.ask.persist_failed', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'response_kind' => $responseKind,
            ]);

            return array_merge($payload, ['help_ai_question_id' => null]);
        }
    }

    /**
     * Authoritative tenant-scoped limits for help answers (plan, usage, registry caps).
     *
     * @return array<string, mixed>
     */
    private function buildWorkspaceFacts(Tenant $tenant): array
    {
        $planKey = $this->planService->getCurrentPlan($tenant);
        $planCfg = config("plans.{$planKey}", []);
        $limits = $this->planService->getPlanLimits($tenant);
        $maxUploadMb = (int) ($limits['max_upload_size_mb'] ?? 0);
        $maxUploadHuman = $maxUploadMb >= 999999
            ? 'Very high per-file cap on this plan (effectively unlimited for normal use).'
            : ($maxUploadMb > 0 ? "{$maxUploadMb} MB per file (subscription cap)" : 'Unknown');

        return [
            'plan' => [
                'key' => $planKey,
                'canonical_key' => $this->planService->getCanonicalPlan($tenant),
                'display_name' => is_string($planCfg['name'] ?? null) ? $planCfg['name'] : $planKey,
            ],
            'limits' => $limits,
            'derived' => [
                'max_upload_size_mb' => $maxUploadMb,
                'max_upload_bytes' => ($maxUploadMb > 0 && $maxUploadMb < 999999) ? $maxUploadMb * 1024 * 1024 : null,
                'max_upload_summary' => $maxUploadHuman,
                'effective_max_ai_credits_per_month' => $this->planService->getEffectiveAiCredits($tenant),
            ],
            'storage' => $this->planService->getStorageInfo($tenant),
            'registry_stricter_per_file_caps' => $this->registryUploadCapsTighterThanPlanMb($maxUploadMb),
            'guidance' => [
                'The effective single-file upload limit is the lower of: subscription max_upload_size_mb (limits) and any stricter per-type cap in registry_stricter_per_file_caps for that file format.',
                'Use limits.* for seats, brands, downloads, tags per asset, custom metadata fields, ZIP sizes, etc.',
                'storage.* is current usage vs plan (plus add-ons when present).',
            ],
        ];
    }

    /**
     * Per-file-type registry caps that are tighter than the plan upload ceiling (when the plan is finite).
     *
     * @return list<array{file_type: string, name: string, max_upload_mb: int}>
     */
    private function registryUploadCapsTighterThanPlanMb(int $planMaxMb): array
    {
        if ($planMaxMb <= 0 || $planMaxMb >= 999999) {
            return [];
        }

        $out = [];
        foreach (config('file_types.types', []) as $key => $cfg) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            $bytes = $cfg['upload']['max_size_bytes'] ?? null;
            if (! is_int($bytes) && ! (is_numeric($bytes))) {
                continue;
            }
            $b = (int) $bytes;
            if ($b <= 0) {
                continue;
            }
            $mb = (int) floor($b / 1024 / 1024);
            if ($mb > 0 && $mb < $planMaxMb) {
                $out[] = [
                    'file_type' => $key,
                    'name' => (string) ($cfg['name'] ?? $key),
                    'max_upload_mb' => $mb,
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => ($a['max_upload_mb'] <=> $b['max_upload_mb']));

        return $out;
    }

    private function buildPrompt(string $userQuestion, string $helpActionsJson, string $workspaceFactsJson): string
    {
        return <<<PROMPT
You are Jackpot in-app help. Ground answers in (1) the JSON array HELP_ACTIONS and (2) the JSON object WORKSPACE_FACTS for this tenant.

HELP_ACTIONS: navigation, steps, short answers, routes — only use keys/titles/urls/steps/short_answer that appear there.
WORKSPACE_FACTS: authoritative numbers for this workspace's subscription (plan name, caps, current storage). Use it for any question about limits, sizes, quotas, credits, seats, brands, downloads, storage used vs allowed, etc.

Hard rules:
- Do NOT invent routes, URLs, permissions, screenshots, features, or workflows that are not clearly supported by HELP_ACTIONS.
- Do NOT reference pages or actions that are not present in HELP_ACTIONS (use only "key", "title", "url", "route_name", "related", "steps", "short_answer" from the payload).
- For numeric limits (upload MB, storage, users, downloads, AI credits, tags per asset, …): you MUST use WORKSPACE_FACTS.limits, WORKSPACE_FACTS.derived, WORKSPACE_FACTS.storage, and WORKSPACE_FACTS.registry_stricter_per_file_caps. Never invent MB, GB, counts, or percentages not present there.
- When both a plan cap and a stricter per-type registry cap apply to a file format, explain that the user is limited by whichever is lower; use registry_stricter_per_file_caps for examples.
- If the user question is not answerable from HELP_ACTIONS or WORKSPACE_FACTS, set confidence_tier to "low" and explain briefly what is missing — do not claim "no documentation" when WORKSPACE_FACTS already answers a limits question.
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

WORKSPACE_FACTS:
{$workspaceFactsJson}

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
