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
        $normalizedQuery = $q === '' ? null : $q;

        $common = $this->pickCommon($visible, $brand);

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
        if (! is_array($required) || $required === []) {
            return true;
        }
        foreach ($required as $permission) {
            if (! is_string($permission) || ! in_array($permission, $userPermissions, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $visible
     * @return list<array<string, mixed>>
     */
    public function pickCommon(array $visible, ?Brand $brand): array
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
                        $relatedOut[] = [
                            'key' => $rk,
                            'title' => (string) ($v['title'] ?? $rk),
                        ];
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
            'steps' => is_array($action['steps'] ?? null) ? $action['steps'] : [],
            'page_label' => (string) ($action['page_label'] ?? ''),
            'route_name' => $routeName,
            'url' => $url,
            'tags' => is_array($action['tags'] ?? null) ? $action['tags'] : [],
            'related' => $relatedOut,
        ];
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
            if (is_string($alias)) {
                $haystacks[] = mb_strtolower($alias);
            }
        }
        $haystacks[] = mb_strtolower((string) ($action['category'] ?? ''));
        $haystacks[] = mb_strtolower((string) ($action['short_answer'] ?? ''));
        foreach ($action['tags'] ?? [] as $tag) {
            if (is_string($tag)) {
                $haystacks[] = mb_strtolower($tag);
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
