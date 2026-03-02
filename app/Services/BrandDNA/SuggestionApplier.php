<?php

namespace App\Services\BrandDNA;

use App\Models\BrandModelVersion;
use LogicException;

/**
 * Deterministic Apply engine for Brand DNA snapshot suggestions.
 * Draft changes only when apply() is called.
 */
class SuggestionApplier
{
    public function apply(BrandModelVersion $draft, array $suggestion): BrandModelVersion
    {
        $key = $suggestion['key'] ?? '';
        $path = $suggestion['path'] ?? '';
        $type = $suggestion['type'] ?? 'informational';
        $value = $suggestion['value'] ?? null;

        if ($type === 'informational') {
            return $draft;
        }

        $payload = $draft->model_payload ?? [];

        if ($type === 'update') {
            $payload = $this->setAtPath($payload, $path, $value);
        } elseif ($type === 'merge') {
            $existing = $this->getAtPath($payload, $path);
            if (! is_array($existing)) {
                $existing = [];
            }
            $newValue = is_array($value) ? $value : [$value];
            $merged = $this->mergeArraysWithoutDuplicates($existing, $newValue);
            $payload = $this->setAtPath($payload, $path, $merged);
        } else {
            return $draft;
        }

        $draft->model_payload = $payload;
        $draft->save();

        return $draft;
    }

    /**
     * Get value at dot-notation path. Returns null if path invalid.
     */
    protected function getAtPath(array $payload, string $path)
    {
        $segments = $this->parsePath($path);
        if ($segments === []) {
            throw new LogicException("Invalid path: {$path}");
        }

        $current = $payload;
        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Set value at dot-notation path. Creates intermediate arrays as needed.
     */
    protected function setAtPath(array $payload, string $path, mixed $value): array
    {
        $segments = $this->parsePath($path);
        if ($segments === []) {
            throw new LogicException("Invalid path: {$path}");
        }

        return $this->setAtPathRecursive($payload, $segments, $value);
    }

    protected function setAtPathRecursive(array $data, array $segments, mixed $value): array
    {
        $segment = array_shift($segments);

        if ($segments === []) {
            $data[$segment] = $value;

            return $data;
        }

        $child = $data[$segment] ?? [];
        if (! is_array($child)) {
            $child = [];
        }
        $data[$segment] = $this->setAtPathRecursive($child, $segments, $value);

        return $data;
    }

    protected function parsePath(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            return [];
        }
        $segments = explode('.', $path);

        return array_values(array_filter(array_map('trim', $segments), fn ($s) => $s !== ''));
    }

    /**
     * Merge arrays, remove duplicates, preserve original order.
     */
    protected function mergeArraysWithoutDuplicates(array $existing, array $new): array
    {
        $seen = [];
        $result = [];

        foreach (array_merge($existing, $new) as $item) {
            $key = is_scalar($item) ? $item : json_encode($item);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $item;
            }
        }

        return $result;
    }
}
