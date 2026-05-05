<?php

namespace App\Services;

use App\Models\Brand;
use Illuminate\Support\Facades\Route;

class HelpActionService
{
    /**
     * @param  list<string>  $userPermissions
     * @return array{query: string|null, results: list<array<string, mixed>>, common: list<array<string, mixed>>}
     */
    public function forRequest(?string $query, array $userPermissions, ?Brand $brand): array
    {
        $actions = config('help_actions.actions', []);
        $visible = [];
        foreach ($actions as $action) {
            if (! is_array($action) || empty($action['key'])) {
                continue;
            }
            if ($this->userCanAccess($action, $userPermissions)) {
                $visible[] = $action;
            }
        }

        $q = $query !== null ? trim($query) : '';
        if ($q !== '' && mb_strlen($q) > 256) {
            $q = mb_substr($q, 0, 256);
        }
        $normalizedQuery = $q === '' ? null : $q;

        $common = $this->pickCommon($visible);

        if ($normalizedQuery === null) {
            return [
                'query' => null,
                'results' => [],
                'common' => array_map(fn (array $a) => $this->serializeAction($a, $brand, $visible), $common),
            ];
        }

        $scored = [];
        foreach ($visible as $action) {
            $score = $this->scoreAction($action, mb_strtolower($normalizedQuery));
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
            'results' => $results,
            'common' => array_map(fn (array $a) => $this->serializeAction($a, $brand, $visible), $common),
        ];
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
        $url = $this->resolveUrl($routeName, $bindings, $brand);

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
            'url' => $this->resolveUrl($routeName, $bindings, $brand),
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
     * @param  array<string, string>  $bindings  route parameter => active_brand | …
     */
    public function resolveUrl(?string $routeName, array $bindings, ?Brand $brand): ?string
    {
        if (! $routeName || ! Route::has($routeName)) {
            return null;
        }

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
            } else {
                // Only `active_brand` is supported; unknown sources skip binding (route() may still succeed or throw — caught below).
            }
        }

        try {
            return route($routeName, $params);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $action
     */
    public function scoreAction(array $action, string $queryLower): int
    {
        $tokens = array_values(array_filter(preg_split('/\s+/u', $queryLower) ?: [], fn ($t) => $t !== ''));
        if ($tokens === []) {
            return 0;
        }

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

        return $score;
    }
}
