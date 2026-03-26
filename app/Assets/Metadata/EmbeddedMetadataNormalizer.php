<?php

namespace App\Assets\Metadata;

/**
 * UTF-8-safe, JSON-stable normalization for Layer B payloads.
 */
class EmbeddedMetadataNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function normalizePayload(array $payload): array
    {
        $out = [];
        foreach ($payload as $namespace => $data) {
            if (! is_string($namespace) || $namespace === '') {
                continue;
            }
            if (! is_array($data)) {
                continue;
            }
            $out[$namespace] = $this->sanitize($data);
        }

        return $out;
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    public function sanitize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            return null;
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return $this->stringify((string) $value);
            }

            return $this->sanitize(json_decode(json_encode($value), true) ?? []);
        }

        if (is_array($value)) {
            $clean = [];
            foreach ($value as $k => $v) {
                if (! is_string($k) && ! is_int($k)) {
                    continue;
                }
                $key = is_int($k) ? (string) $k : $this->stringify((string) $k);
                $sanitized = $this->sanitize($v);
                if ($sanitized === null && ! is_array($v)) {
                    continue;
                }
                $clean[$key] = $sanitized;
            }

            return $clean;
        }

        if (is_float($value) || is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $this->stringify($value);
        }

        return null;
    }

    public function stringify(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return str_replace("\0", '', $value);
    }
}
