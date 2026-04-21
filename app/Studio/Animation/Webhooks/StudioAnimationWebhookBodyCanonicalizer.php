<?php

namespace App\Studio\Animation\Webhooks;

/**
 * Produces alternative request-body byte sequences for webhook signature verification
 * (some providers canonicalize JSON before signing).
 */
final class StudioAnimationWebhookBodyCanonicalizer
{
    /**
     * @return list<string>
     */
    public static function signatureBodyCandidates(string $rawBody): array
    {
        $out = [$rawBody];
        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $out;
        }
        if (! is_array($decoded)) {
            return $out;
        }
        $sorted = self::ksortRecursive($decoded);
        $canonical = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($canonical !== $rawBody) {
            $out[] = $canonical;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function ksortRecursive(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::ksortRecursive($v);
            }
        }
        ksort($data);

        return $data;
    }
}
