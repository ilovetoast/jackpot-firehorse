<?php

namespace App\Studio\Animation\Providers\Kling;

/**
 * HS256 JWT for Kling’s official API ({@see https://app.klingai.com/global/dev/model-api}).
 * Matches the token shape used by the official Kling client libraries (iss=Access Key, HMAC with Secret Key).
 */
final class KlingNativeJwt
{
    public static function sign(string $accessKey, string $secretKey): string
    {
        $header = self::base64UrlEncode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $now = time();
        $payload = self::base64UrlEncode((string) json_encode([
            'iss' => $accessKey,
            'exp' => $now + 1800,
            'nbf' => $now - 5,
        ], JSON_UNESCAPED_SLASHES));
        $unsigned = $header.'.'.$payload;
        $signature = self::base64UrlEncode(hash_hmac('sha256', $unsigned, $secretKey, true));

        return $unsigned.'.'.$signature;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
