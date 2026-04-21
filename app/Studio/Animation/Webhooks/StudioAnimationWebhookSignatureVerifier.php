<?php

namespace App\Studio\Animation\Webhooks;

use Illuminate\Http\Request;

/**
 * Optional HMAC verification for inbound webhooks (fal-style) plus shared-secret gate in controller.
 * Tries raw body and JSON-canonical variants so signing matches common provider implementations.
 */
final class StudioAnimationWebhookSignatureVerifier
{
    /**
     * @return array{ok: bool, verified: bool, method: string, detail: string|null}
     */
    public function verifyProviderPayload(Request $request, string $provider): array
    {
        $secret = (string) config('studio_animation.webhooks.fal_signature_secret', '');
        if ($secret === '') {
            return [
                'ok' => true,
                'verified' => false,
                'method' => 'none',
                'detail' => 'fal_signature_secret_not_configured',
            ];
        }

        $body = $request->getContent();
        $header = (string) ($request->header('X-Fal-Signature')
            ?? $request->header('X-Fal-Webhook-Signature')
            ?? $request->header('Fal-Signature')
            ?? $request->header('X-Fal-Signature-256')
            ?? '');

        if ($header === '') {
            return [
                'ok' => false,
                'verified' => false,
                'method' => 'hmac_sha256',
                'detail' => 'missing_signature_header',
            ];
        }

        $headerCandidates = $this->normalizeSignatureCandidates($header);
        $bodyCandidates = StudioAnimationWebhookBodyCanonicalizer::signatureBodyCandidates($body);

        foreach ($bodyCandidates as $bodyVariant) {
            $expected = hash_hmac('sha256', $bodyVariant, $secret);
            foreach ($headerCandidates as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    return [
                        'ok' => true,
                        'verified' => true,
                        'method' => 'hmac_sha256',
                        'detail' => null,
                    ];
                }
            }
        }

        return [
            'ok' => false,
            'verified' => false,
            'method' => 'hmac_sha256',
            'detail' => 'signature_mismatch',
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeSignatureCandidates(string $header): array
    {
        $h = trim($header);
        $out = [$h];
        if (str_starts_with($h, 'sha256=')) {
            $out[] = substr($h, 7);
        }
        if (str_starts_with($h, 'v1=')) {
            $out[] = substr($h, 3);
        }

        return array_values(array_unique(array_filter($out, static fn ($v) => $v !== '')));
    }
}
