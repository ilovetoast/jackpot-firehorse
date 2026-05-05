<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Roles\PermissionMap;
use Illuminate\Support\Facades\Route;

class HelpActionService
{
    public function __construct(
        protected FeatureGate $featureGate,
    ) {}

    /**
     * @param  list<string>  $userPermissions
     * @return array{query: string|null, contextual: list<array<string, mixed>>, results: list<array<string, mixed>>, common: list<array<string, mixed>>}
     */
    public function forRequest(
        ?string $query,
        array $userPermissions,
        ?Brand $brand,
        ?string $contextRouteName = null,
        ?string $contextPageLabel = null,
        ?HelpActionVisibilityContext $visibilityContext = null,
    ): array {
        $visible = $this->visibleActions($userPermissions, $visibilityContext);

        $routeCtx = $this->normalizeContextRouteName($contextRouteName);
        $pageCtx = $this->normalizeContextPageLabel($contextPageLabel);

        $contextualRaw = $this->pickContextualActions($visible, $routeCtx, $pageCtx);
        $contextualKeys = [];
        foreach ($contextualRaw as $a) {
            $contextualKeys[(string) $a['key']] = true;
        }

        $q = $query !== null ? trim($query) : '';
        if ($q !== '' && mb_strlen($q) > 256) {
            $q = mb_substr($q, 0, 256);
        }
        $normalizedQuery = $q === '' ? null : $q;

        $common = $this->pickCommon($visible);
        $commonFiltered = array_values(array_filter($common, function (array $a) use ($contextualKeys) {
            return ! isset($contextualKeys[(string) $a['key']]);
        }));

        $serialize = fn (array $a) => $this->serializeAction($a, $brand, $visible);
        $contextualOut = array_map($serialize, $contextualRaw);

        if ($normalizedQuery === null) {
            return [
                'query' => null,
                'contextual' => $contextualOut,
                'results' => [],
                'common' => array_map($serialize, $commonFiltered),
            ];
        }

        $scored = [];
        $lowerQ = mb_strtolower($normalizedQuery);
        foreach ($visible as $action) {
            $score = $this->scoreAction($action, $lowerQ, $routeCtx, $pageCtx);
            if ($score > 0) {
                $scored[] = ['action' => $action, 'score' => $score];
            }
        }
        usort($scored, function (array $a, array $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return strcmp((string) $a['action']['title'], (string) $b['action']['title']);
        });

        $results = array_map(fn (array $row) => $this->serializeAction($row['action'], $brand, $visible), $scored);

        return [
            'query' => $normalizedQuery,
            'contextual' => $contextualOut,
            'results' => $results,
            'common' => array_map($serialize, $commonFiltered),
        ];
    }

    /**
     * Whether a workspace feature flag / entitlement is on for this user+tenant(+brand).
     * Used by help visibility and Ask AI guardrails.
     *
     * Known keys: generative, ai, creator_module, workspace_insights, agency_workspace
     */
    public function isUserFacingFeatureEnabled(string $featureKey, User $user, Tenant $tenant, ?Brand $brand): bool
    {
        $ctx = new HelpActionVisibilityContext($user, $tenant, $brand);

        return $this->featureEnabledForContext($featureKey, $ctx);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<string>  $userPermissions
     */
    public function userCanAccess(array $action, array $userPermissions): bool
    {
        $required = $action['permissions'] ?? [];
        if (! is_array($required)) {
            $required = [];
        }
        if ($required === []) {
            return true;
        }
        foreach ($required as $permission) {
            $p = is_string($permission) ? $permission : (is_scalar($permission) ? (string) $permission : null);
            if ($p === null || $p === '' || ! in_array($p, $userPermissions, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $userPermissions
     * @return list<array<string, mixed>>
     */
    public function visibleActions(array $userPermissions, ?HelpActionVisibilityContext $context = null): array
    {
        $actions = config('help_actions.actions', []);
        $visible = [];
        foreach ($actions as $action) {
            if (! is_array($action) || empty($action['key'])) {
                continue;
            }
            if (! $this->userCanAccess($action, $userPermissions)) {
                continue;
            }
            if ($context !== null && ! $this->actionVisibleInWorkspaceContext($action, $context)) {
                continue;
            }
            $visible[] = $action;
        }

        return $visible;
    }

    /**
     * Rank visible help actions for a natural-language question (same scoring as search).
     *
     * @param  list<string>  $userPermissions
     * @return array{serialized: list<array<string, mixed>>, best_score: int, matched_keys: list<string>}
     */
    public function rankForNaturalLanguageQuestion(
        string $question,
        array $userPermissions,
        ?Brand $brand,
        int $limit = 5,
        ?string $contextRouteName = null,
        ?string $contextPageLabel = null,
        ?HelpActionVisibilityContext $visibilityContext = null,
    ): array {
        $visible = $this->visibleActions($userPermissions, $visibilityContext);
        $q = trim($question);
        if ($q !== '' && mb_strlen($q) > 2000) {
            $q = mb_substr($q, 0, 2000);
        }
        if ($q === '') {
            return ['serialized' => [], 'best_score' => 0, 'matched_keys' => []];
        }
        $lower = mb_strtolower($q);
        $routeCtx = $this->normalizeContextRouteName($contextRouteName);
        $pageCtx = $this->normalizeContextPageLabel($contextPageLabel);
        $scored = [];
        foreach ($visible as $action) {
            $score = $this->scoreAction($action, $lower, $routeCtx, $pageCtx);
            if ($score > 0) {
                $scored[] = ['action' => $action, 'score' => $score];
            }
        }
        usort($scored, function (array $a, array $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return strcmp((string) $a['action']['title'], (string) $b['action']['title']);
        });
        $top = array_slice($scored, 0, max(1, $limit));
        $serialized = array_map(fn (array $row) => $this->serializeAction($row['action'], $brand, $visible), $top);
        $best = $scored[0]['score'] ?? 0;
        $keys = array_map(fn (array $row) => (string) $row['action']['key'], $top);

        return [
            'serialized' => $serialized,
            'best_score' => (int) $best,
            'matched_keys' => $keys,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $visible
     * @return list<array<string, mixed>>
     */
    public function pickCommon(array $visible): array
    {
        $pinned = [];
        foreach ($visible as $action) {
            if (! empty($action['in_common'])) {
                $pinned[] = $action;
            }
        }
        usort($pinned, function (array $a, array $b) {
            $ao = (int) ($a['common_sort'] ?? 1000);
            $bo = (int) ($b['common_sort'] ?? 1000);
            if ($ao !== $bo) {
                return $ao <=> $bo;
            }

            return strcmp((string) $a['title'], (string) $b['title']);
        });

        return $pinned;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  list<array<string, mixed>>  $visible
     * @return array<string, mixed>
     */
    public function serializeAction(array $action, ?Brand $brand, array $visible): array
    {
        $routeName = isset($action['route_name']) && is_string($action['route_name']) ? $action['route_name'] : null;
        $bindings = isset($action['route_bindings']) && is_array($action['route_bindings']) ? $action['route_bindings'] : [];
        $url = $this->resolvePrimaryUrl($action, $routeName, $bindings, $brand);
        $deepLinkOut = $this->serializeDeepLinkForResponse($action['deep_link'] ?? null, $brand);
        $highlightOut = $this->sanitizeHighlight($action['highlight'] ?? null, $brand);
        $requiresContextOut = $this->sanitizeRequiresContext($action['requires_context'] ?? null);

        $relatedOut = [];
        $visibleKeys = [];
        foreach ($visible as $v) {
            $visibleKeys[(string) $v['key']] = true;
        }
        $relatedKeys = $action['related'] ?? [];
        if (is_array($relatedKeys)) {
            foreach ($relatedKeys as $rk) {
                if (! is_string($rk) || ! isset($visibleKeys[$rk])) {
                    continue;
                }
                foreach ($visible as $v) {
                    if ((string) $v['key'] === $rk) {
                        $relatedOut[] = $this->serializeRelatedTarget($v, $brand);
                        break;
                    }
                }
            }
        }

        return [
            'key' => (string) $action['key'],
            'title' => (string) ($action['title'] ?? ''),
            'category' => (string) ($action['category'] ?? ''),
            'short_answer' => (string) ($action['short_answer'] ?? ''),
            'steps' => $this->sanitizeSteps($action['steps'] ?? null),
            'page_label' => (string) ($action['page_label'] ?? ''),
            'route_name' => $routeName,
            'url' => $url,
            'deep_link' => $deepLinkOut,
            'highlight' => $highlightOut,
            'requires_context' => $requiresContextOut,
            'tags' => $this->sanitizeStringList($action['tags'] ?? null),
            'related' => $relatedOut,
        ];
    }

    /**
     * Related topic payload for the panel (no nested related — avoids large graphs).
     *
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    public function serializeRelatedTarget(array $target, ?Brand $brand): array
    {
        $routeName = isset($target['route_name']) && is_string($target['route_name']) ? $target['route_name'] : null;
        $bindings = isset($target['route_bindings']) && is_array($target['route_bindings']) ? $target['route_bindings'] : [];

        return [
            'key' => (string) $target['key'],
            'title' => (string) ($target['title'] ?? ''),
            'category' => (string) ($target['category'] ?? ''),
            'short_answer' => (string) ($target['short_answer'] ?? ''),
            'steps' => $this->sanitizeSteps($target['steps'] ?? null),
            'page_label' => (string) ($target['page_label'] ?? ''),
            'route_name' => $routeName,
            'url' => $this->resolvePrimaryUrl($target, $routeName, $bindings, $brand),
            'deep_link' => $this->serializeDeepLinkForResponse($target['deep_link'] ?? null, $brand),
            'highlight' => $this->sanitizeHighlight($target['highlight'] ?? null, $brand),
            'requires_context' => $this->sanitizeRequiresContext($target['requires_context'] ?? null),
            'tags' => $this->sanitizeStringList($target['tags'] ?? null),
            'related' => [],
        ];
    }

    /**
     * @return list<string>
     */
    public function sanitizeSteps(mixed $steps): array
    {
        if (! is_array($steps)) {
            return [];
        }
        $out = [];
        foreach ($steps as $step) {
            if (is_string($step) && $step !== '') {
                $out[] = $step;
            } elseif (is_scalar($step) && (string) $step !== '') {
                $out[] = (string) $step;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function sanitizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            } elseif (is_scalar($item) && (string) $item !== '') {
                $out[] = (string) $item;
            }
        }

        return $out;
    }

    /**
     * Prefer `deep_link` when it resolves; otherwise `route_name` + `route_bindings`.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, string>  $bindings  route parameter => active_brand | …
     */
    public function resolvePrimaryUrl(array $action, ?string $routeName, array $bindings, ?Brand $brand): ?string
    {
        $deep = $this->parseDeepLinkConfig($action['deep_link'] ?? null);
        if ($deep !== null) {
            $u = $this->resolveUrlWithQuery($deep['route_name'], $deep['params'], $deep['query'], $brand);
            if ($u !== null) {
                return $u;
            }
        }

        return $this->resolveUrl($routeName, $bindings, $brand);
    }

    /**
     * Safe `deep_link` object for JSON (only if route exists and core shape is valid).
     *
     * @return array{route_name: string, params: array<string, int|string>, query: array<string, string>}|null
     */
    public function serializeDeepLinkForResponse(mixed $deepLink, ?Brand $brand): ?array
    {
        $deep = $this->parseDeepLinkConfig($deepLink);
        if ($deep === null || ! Route::has($deep['route_name'])) {
            return null;
        }
        $resolvedParams = $this->resolveBindingParams($deep['params'], $brand);
        if ($resolvedParams === null) {
            return null;
        }
        try {
            route($deep['route_name'], $resolvedParams);
        } catch (\Throwable) {
            return null;
        }

        return [
            'route_name' => $deep['route_name'],
            'params' => $resolvedParams,
            'query' => $deep['query'],
        ];
    }

    /**
     * Highlight targets use `[data-help="selector"]` on the frontend. Config may use a bare token or `[data-help="token"]`.
     *
     * @return array<string, mixed>|null
     */
    public function sanitizeHighlight(mixed $highlight, ?Brand $brand = null): ?array
    {
        if (! is_array($highlight)) {
            return null;
        }
        $selector = $this->normalizeHelpDataSelector($highlight['selector'] ?? null);
        if ($selector === null) {
            return null;
        }
        $out = ['selector' => $selector];

        $label = $highlight['label'] ?? null;
        if (is_string($label)) {
            $label = trim($label);
            if ($label !== '') {
                $out['label'] = mb_strlen($label) > 200 ? mb_substr($label, 0, 200) : $label;
            }
        } elseif (is_scalar($label) && (string) $label !== '') {
            $s = trim((string) $label);
            if ($s !== '') {
                $out['label'] = mb_strlen($s) > 200 ? mb_substr($s, 0, 200) : $s;
            }
        }

        $fb = $this->normalizeHelpDataSelector($highlight['fallback_selector'] ?? null);
        if ($fb !== null) {
            $out['fallback_selector'] = $fb;
        }
        $fbl = $highlight['fallback_label'] ?? null;
        if (is_string($fbl)) {
            $fbl = trim($fbl);
            if ($fbl !== '') {
                $out['fallback_label'] = mb_strlen($fbl) > 200 ? mb_substr($fbl, 0, 200) : $fbl;
            }
        }

        foreach (
            [
                'missing_title' => 160,
                'missing_message' => 600,
                'missing_cta_label' => 120,
            ] as $field => $max
        ) {
            $v = $highlight[$field] ?? null;
            if (! is_string($v)) {
                continue;
            }
            $v = trim($v);
            if ($v === '') {
                continue;
            }
            $out[$field] = mb_strlen($v) > $max ? mb_substr($v, 0, $max) : $v;
        }

        $ctaRoute = $highlight['missing_cta_route'] ?? null;
        if (is_string($ctaRoute)) {
            $ctaRoute = trim($ctaRoute);
            if ($ctaRoute !== '' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $ctaRoute)) {
                $ctaBindings = $this->sanitizeHighlightCtaBindings($highlight['missing_cta_route_bindings'] ?? null);
                $ctaUrl = $this->resolveHighlightMissingCtaUrl($ctaRoute, $brand, $ctaBindings);
                if ($ctaUrl !== null) {
                    $out['missing_cta_url'] = $ctaUrl;
                }
            }
        }

        return $out;
    }

    /**
     * @return array{type: string, message?: string}|null
     */
    public function sanitizeRequiresContext(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $type = $value['type'] ?? null;
        if (! is_string($type) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $type)) {
            return null;
        }
        $out = ['type' => $type];
        $msg = $value['message'] ?? null;
        if (is_string($msg)) {
            $msg = trim($msg);
            if ($msg !== '') {
                $out['message'] = mb_strlen($msg) > 500 ? mb_substr($msg, 0, 500) : $msg;
            }
        }

        return $out;
    }

    private function normalizeHelpDataSelector(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $s = trim($raw);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^\[data-help="([a-z0-9][a-z0-9_.-]{0,63})"\]$/', $s, $m)) {
            return $m[1];
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $s)) {
            return $s;
        }

        return null;
    }

    /**
     * @param  array<string, string>  $bindings  route parameter => active_brand
     */
    private function sanitizeHighlightCtaBindings(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            if (! is_string($k) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $k)) {
                continue;
            }
            if ($v === 'active_brand') {
                $out[$k] = 'active_brand';
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $bindings
     */
    private function resolveHighlightMissingCtaUrl(string $routeName, ?Brand $brand, array $bindings = []): ?string
    {
        if (! Route::has($routeName)) {
            return null;
        }

        try {
            return $this->resolveUrl($routeName, $bindings, $brand);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{route_name: string, params: array<string, string>, query: array<string, string>}|null
     */
    private function parseDeepLinkConfig(mixed $deepLink): ?array
    {
        if (! is_array($deepLink)) {
            return null;
        }
        $name = $deepLink['route_name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            return null;
        }
        $name = trim($name);
        $paramsIn = $deepLink['params'] ?? [];
        if (! is_array($paramsIn)) {
            $paramsIn = [];
        }
        $params = [];
        foreach ($paramsIn as $param => $source) {
            if (! is_string($param) || $param === '' || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $param)) {
                continue;
            }
            if (is_string($source)) {
                $params[$param] = $source;
            }
        }
        $queryIn = $deepLink['query'] ?? [];
        $query = $this->sanitizeQueryAssoc($queryIn);

        return [
            'route_name' => $name,
            'params' => $params,
            'query' => $query,
        ];
    }

    /**
     * @param  array<string, string>  $bindings  route parameter => active_brand
     * @return array<string, int|string>|null null when active_brand required but missing
     */
    private function resolveBindingParams(array $bindings, ?Brand $brand): ?array
    {
        $params = [];
        foreach ($bindings as $param => $source) {
            if (! is_string($param)) {
                continue;
            }
            if ($source === 'active_brand') {
                if (! $brand) {
                    return null;
                }
                $params[$param] = $brand->id;
            }
        }

        return $params;
    }

    /**
     * @return array<string, string>
     */
    private function sanitizeQueryAssoc(mixed $query): array
    {
        if (! is_array($query)) {
            return [];
        }
        $out = [];
        $n = 0;
        foreach ($query as $k => $v) {
            if ($n >= 24) {
                break;
            }
            if (! is_string($k) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $k)) {
                continue;
            }
            if (is_string($v)) {
                $s = trim($v);
            } elseif (is_int($v) || is_float($v)) {
                $s = (string) $v;
            } elseif (is_bool($v)) {
                $s = $v ? '1' : '0';
            } else {
                continue;
            }
            if (mb_strlen($s) > 256) {
                $s = mb_substr($s, 0, 256);
            }
            if ($s === '') {
                continue;
            }
            $out[$k] = $s;
            $n++;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $bindings
     */
    private function resolveUrlWithQuery(string $routeName, array $bindings, array $query, ?Brand $brand): ?string
    {
        $base = $this->resolveUrl($routeName, $bindings, $brand);
        if ($base === null || $query === []) {
            return $base;
        }
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if ($qs === '') {
            return $base;
        }

        return $base.(str_contains($base, '?') ? '&' : '?').$qs;
    }

    /**
     * @param  array<string, string>  $bindings  route parameter => active_brand | …
     */
    public function resolveUrl(?string $routeName, array $bindings, ?Brand $brand): ?string
    {
        if (! $routeName || ! Route::has($routeName)) {
            return null;
        }

        $resolved = $this->resolveBindingParams($bindings, $brand);
        if ($resolved === null) {
            return null;
        }

        try {
            return route($routeName, $resolved);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $action
     */
    public function scoreAction(
        array $action,
        string $queryLower,
        ?string $contextRouteName = null,
        ?string $contextPageLabel = null,
    ): int {
        $tokens = array_values(array_filter(preg_split('/\s+/u', $queryLower) ?: [], fn ($t) => $t !== ''));
        if ($tokens === []) {
            return 0;
        }

        $haystacks = $this->helpActionSearchHaystacks($action);

        $score = 0;
        foreach ($tokens as $token) {
            foreach ($haystacks as $h) {
                if ($h === '') {
                    continue;
                }
                if (str_starts_with($h, $token)) {
                    $score += 12;
                } elseif (str_contains($h, $token)) {
                    $score += 6;
                }
            }
        }

        $full = trim($queryLower);
        if ($full !== '' && (mb_strlen($full) >= 10 || substr_count($full, ' ') >= 2)) {
            foreach ($haystacks as $h) {
                if ($h !== '' && str_contains($h, $full)) {
                    $score += 50;
                    break;
                }
            }
        }

        if ($this->matchesRouteOrPageContext($action, $contextRouteName, $contextPageLabel)) {
            $prio = (int) ($action['priority'] ?? 0);
            $score += 20 + min(100, max(0, $prio));
        }

        return $score;
    }

    /**
     * Text sources used for help search scoring (token + phrase match).
     *
     * @param  array<string, mixed>  $action
     * @return list<string>
     */
    private function helpActionSearchHaystacks(array $action): array
    {
        $haystacks = [];
        $haystacks[] = mb_strtolower((string) ($action['title'] ?? ''));
        foreach ($action['aliases'] ?? [] as $alias) {
            if (is_string($alias) && $alias !== '') {
                $haystacks[] = mb_strtolower($alias);
            } elseif (is_scalar($alias) && (string) $alias !== '') {
                $haystacks[] = mb_strtolower((string) $alias);
            }
        }
        $haystacks[] = mb_strtolower((string) ($action['category'] ?? ''));
        $haystacks[] = mb_strtolower((string) ($action['short_answer'] ?? ''));
        foreach ($action['tags'] ?? [] as $tag) {
            if (is_string($tag) && $tag !== '') {
                $haystacks[] = mb_strtolower($tag);
            } elseif (is_scalar($tag) && (string) $tag !== '') {
                $haystacks[] = mb_strtolower((string) $tag);
            }
        }

        return $haystacks;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function actionVisibleInWorkspaceContext(array $action, HelpActionVisibilityContext $ctx): bool
    {
        if (! $this->passesRequiredDisabledFeatures($action, $ctx)) {
            return false;
        }
        if (! $this->passesTenantRoleGates($action, $ctx)) {
            return false;
        }
        if (! $this->passesRequiredModules($action, $ctx)) {
            return false;
        }
        if (! $this->passesRequiredPlanFeatures($action, $ctx)) {
            return false;
        }
        if (! $this->passesRequiredPlanFeaturesAny($action, $ctx)) {
            return false;
        }
        if (! $this->passesRequiredDisabledPlanFeatures($action, $ctx)) {
            return false;
        }
        $mustBeOn = array_merge(
            $this->stringPermissionLikeList($action['required_features'] ?? null),
            $this->stringPermissionLikeList($action['hidden_when_features_disabled'] ?? null),
        );
        foreach (array_unique($mustBeOn) as $featureKey) {
            if (! $this->featureEnabledForContext($featureKey, $ctx)) {
                return false;
            }
        }
        if (! empty($action['requires_brand_approver']) && ! $this->userCanApproveOnActiveBrand($ctx)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function passesRequiredDisabledFeatures(array $action, HelpActionVisibilityContext $ctx): bool
    {
        $req = $action['required_disabled_features'] ?? null;
        if (! is_array($req) || $req === []) {
            return true;
        }
        foreach ($req as $f) {
            if (! is_string($f) || $f === '') {
                continue;
            }
            if ($this->featureEnabledForContext($f, $ctx)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function passesTenantRoleGates(array $action, HelpActionVisibilityContext $ctx): bool
    {
        $role = strtolower((string) ($ctx->user->getRoleForTenant($ctx->tenant) ?? ''));
        if (! empty($action['requires_owner']) && $role !== 'owner') {
            return false;
        }
        if (! empty($action['requires_admin']) && ! in_array($role, ['owner', 'admin', 'agency_admin'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function passesRequiredModules(array $action, HelpActionVisibilityContext $ctx): bool
    {
        $mods = $action['required_modules'] ?? null;
        if (! is_array($mods) || $mods === []) {
            return true;
        }
        foreach ($mods as $m) {
            if (! is_string($m) || $m === '') {
                continue;
            }
            if ($m === 'creator_module' && ! $this->featureGate->creatorModuleEnabled($ctx->tenant)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function passesRequiredPlanFeatures(array $action, HelpActionVisibilityContext $ctx): bool
    {
        $keys = $action['required_plan_features'] ?? null;
        if (! is_array($keys) || $keys === []) {
            return true;
        }
        foreach ($keys as $k) {
            if (! is_string($k) || $k === '') {
                continue;
            }
            if (! $this->planCapabilityEnabled($ctx->tenant, $k)) {
                return false;
            }
        }

        return true;
    }

    /**
     * When set, at least one listed plan capability must be enabled (OR).
     *
     * @param  array<string, mixed>  $action
     */
    private function passesRequiredPlanFeaturesAny(array $action, HelpActionVisibilityContext $ctx): bool
    {
        $keys = $action['required_plan_features_any'] ?? null;
        if (! is_array($keys) || $keys === []) {
            return true;
        }
        foreach ($keys as $k) {
            if (! is_string($k) || $k === '') {
                continue;
            }
            if ($this->planCapabilityEnabled($ctx->tenant, $k)) {
                return true;
            }
        }

        return false;
    }

    /**
     * When set, every listed plan capability must be disabled (AND NOT) — for honest “not available” explainers.
     *
     * @param  array<string, mixed>  $action
     */
    private function passesRequiredDisabledPlanFeatures(array $action, HelpActionVisibilityContext $ctx): bool
    {
        $keys = $action['required_disabled_plan_features'] ?? null;
        if (! is_array($keys) || $keys === []) {
            return true;
        }
        foreach ($keys as $k) {
            if (! is_string($k) || $k === '') {
                continue;
            }
            if ($this->planCapabilityEnabled($ctx->tenant, $k)) {
                return false;
            }
        }

        return true;
    }

    private function planCapabilityEnabled(Tenant $tenant, string $key): bool
    {
        if (str_contains($key, 'approvals.') || str_contains($key, 'notifications.') || str_contains($key, 'approval_summaries')) {
            return $this->featureGate->allows($tenant, $key);
        }

        return match ($key) {
            'public_collections_enabled' => $this->featureGate->publicCollectionsEnabled($tenant),
            'download_password_protection' => $this->featureGate->downloadPasswordProtectionEnabled($tenant),
            default => true,
        };
    }

    private function featureEnabledForContext(string $featureKey, HelpActionVisibilityContext $ctx): bool
    {
        return match ($featureKey) {
            'generative', 'studio' => ($ctx->tenant->settings['generative_enabled'] ?? true) === true,
            'ai' => ($ctx->tenant->settings['ai_enabled'] ?? true) === true,
            'creator_module' => $this->featureGate->creatorModuleEnabled($ctx->tenant),
            'workspace_insights' => $ctx->brand !== null && $ctx->user->canViewBrandWorkspaceInsights($ctx->tenant, $ctx->brand),
            'agency_workspace' => $this->userHasAgencyWorkspaceAccess($ctx),
            default => true,
        };
    }

    private function userHasAgencyWorkspaceAccess(HelpActionVisibilityContext $ctx): bool
    {
        if ($ctx->tenant->is_agency) {
            return true;
        }

        return $ctx->user->tenants()->where('tenants.is_agency', true)->exists();
    }

    private function userCanApproveOnActiveBrand(HelpActionVisibilityContext $ctx): bool
    {
        if ($ctx->brand === null) {
            return false;
        }
        $membership = $ctx->user->activeBrandMembership($ctx->brand);
        $role = $membership['role'] ?? null;
        if (! is_string($role) || $role === '') {
            return false;
        }

        return PermissionMap::canApproveAssets($role);
    }

    /**
     * @return list<string>
     */
    private function stringPermissionLikeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function normalizeContextRouteName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 191) {
            return null;
        }

        return $name;
    }

    private function normalizeContextPageLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 128) {
            return null;
        }

        return $label;
    }

    /**
     * @param  list<array<string, mixed>>  $visible
     * @return list<array<string, mixed>>
     */
    private function pickContextualActions(array $visible, ?string $routeName, ?string $pageLabel): array
    {
        if ($routeName === null && $pageLabel === null) {
            return [];
        }
        $picked = [];
        foreach ($visible as $action) {
            if ($this->matchesRouteOrPageContext($action, $routeName, $pageLabel)) {
                $picked[] = $action;
            }
        }
        usort($picked, function (array $a, array $b) {
            $pa = (int) ($a['priority'] ?? 0);
            $pb = (int) ($b['priority'] ?? 0);
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }
            $ca = (int) ($a['common_sort'] ?? 1000);
            $cb = (int) ($b['common_sort'] ?? 1000);
            if ($ca !== $cb) {
                return $ca <=> $cb;
            }

            return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });

        return $picked;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function matchesRouteOrPageContext(array $action, ?string $currentRouteName, ?string $requestPageLabel): bool
    {
        $routeHit = false;
        if ($currentRouteName !== null) {
            $routes = $action['routes'] ?? null;
            if (is_array($routes) && $routes !== []) {
                foreach ($routes as $r) {
                    if (is_string($r) && $r !== '' && $r === $currentRouteName) {
                        $routeHit = true;
                        break;
                    }
                }
            } else {
                $primary = isset($action['route_name']) && is_string($action['route_name']) ? $action['route_name'] : null;
                if ($primary !== null && $primary === $currentRouteName) {
                    $routeHit = true;
                }
            }
        }

        $pageHit = false;
        if ($requestPageLabel !== null) {
            $want = mb_strtolower($requestPageLabel);
            foreach ($action['page_context'] ?? [] as $p) {
                if (is_string($p) && mb_strtolower(trim($p)) === $want) {
                    $pageHit = true;
                    break;
                }
            }
        }

        return $routeHit || $pageHit;
    }
}
