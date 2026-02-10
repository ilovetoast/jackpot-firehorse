<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Scoped asset search: filename, title, user/AI tags, collection name only.
 * Tokens ANDed; quoted phrases exact; partial word matches.
 * No type/dates/numeric/hidden fields. Composes into existing query pipeline.
 */
class AssetSearchService
{
    /**
     * Apply search to the asset query (before pagination/order).
     * Scope is already set by caller (tenant, brand, type, category).
     *
     * @param Builder $query Asset query builder (already scoped)
     * @param string $q Search string (e.g. from ?q=)
     */
    public function applyScopedSearch(Builder $query, string $q): void
    {
        $q = trim($q);
        if ($q === '') {
            return;
        }

        $tokens = $this->parseSearchQuery($q);
        if (empty($tokens)) {
            return;
        }

        $driver = $query->getConnection()->getDriverName();
        $like = $driver === 'pgsql' ? 'ilike' : 'like';

        $query->where(function (Builder $q) use ($tokens, $like) {
            foreach ($tokens as $token) {
                $pattern = '%' . $this->escapeLike($token) . '%';
                $q->where(function (Builder $or) use ($pattern, $like) {
                    // Match if token appears in title OR filename OR any tag OR any collection name
                    $or->where('title', $like, $pattern)
                        ->orWhere('original_filename', $like, $pattern)
                        ->orWhereExists(function ($sub) use ($pattern, $like) {
                            $sub->select(DB::raw(1))
                                ->from('asset_tags')
                                ->whereColumn('asset_tags.asset_id', 'assets.id')
                                ->where('asset_tags.tag', $like, $pattern);
                        })
                        ->orWhereExists(function ($sub) use ($pattern, $like) {
                            $sub->select(DB::raw(1))
                                ->from('asset_collections')
                                ->join('collections', 'collections.id', '=', 'asset_collections.collection_id')
                                ->whereColumn('asset_collections.asset_id', 'assets.id')
                                ->where('collections.name', $like, $pattern);
                        });
                });
            }
        });
    }

    /**
     * Parse q into tokens: quoted phrases preserved as one token, rest split by spaces; ANDed.
     */
    private function parseSearchQuery(string $q): array
    {
        $tokens = [];
        $len = strlen($q);
        $i = 0;

        while ($i < $len) {
            // Skip spaces
            while ($i < $len && $q[$i] === ' ') {
                $i++;
            }
            if ($i >= $len) {
                break;
            }

            if ($q[$i] === '"') {
                $i++;
                $end = $i;
                while ($end < $len && $q[$end] !== '"') {
                    if ($q[$end] === '\\') {
                        $end++;
                    }
                    $end++;
                }
                $phrase = substr($q, $i, $end - $i);
                $phrase = trim(stripcslashes($phrase));
                if ($phrase !== '') {
                    $tokens[] = $phrase;
                }
                $i = $end + 1;
                continue;
            }

            $start = $i;
            while ($i < $len && $q[$i] !== ' ' && $q[$i] !== '"') {
                $i++;
            }
            $word = trim(substr($q, $start, $i - $start));
            if ($word !== '') {
                $tokens[] = $word;
            }
        }

        return $tokens;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
    }
}
