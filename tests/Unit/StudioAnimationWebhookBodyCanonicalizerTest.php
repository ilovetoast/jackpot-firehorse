<?php

namespace Tests\Unit;

use App\Studio\Animation\Webhooks\StudioAnimationWebhookBodyCanonicalizer;
use App\Studio\Animation\Webhooks\StudioAnimationWebhookSignatureVerifier;
use Illuminate\Http\Request;
use Tests\TestCase;

final class StudioAnimationWebhookBodyCanonicalizerTest extends TestCase
{
    public function test_hmac_matches_canonical_json_when_raw_differs(): void
    {
        $secret = 'fixture-secret';
        $canonical = '{"request_id":"r1","status":"COMPLETED"}';
        $expectedSig = hash_hmac('sha256', $canonical, $secret);

        $raw = "{\n  \"status\": \"COMPLETED\",\n  \"request_id\": \"r1\"\n}";
        $this->assertNotSame($canonical, $raw);

        $request = Request::create(
            '/webhooks/studio-animation/kling',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_FAL_SIGNATURE' => $expectedSig],
            $raw,
        );

        config(['studio_animation.webhooks.fal_signature_secret' => $secret]);
        $v = new StudioAnimationWebhookSignatureVerifier;
        $r = $v->verifyProviderPayload($request, 'kling');
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['verified']);
    }

    public function test_canonicalizer_produces_sorted_variant(): void
    {
        $raw = '{"b":2,"a":1}';
        $c = StudioAnimationWebhookBodyCanonicalizer::signatureBodyCandidates($raw);
        $this->assertContains('{"a":1,"b":2}', $c);
    }

    public function test_fixture_sample_matches_hmac(): void
    {
        $path = base_path('tests/Fixtures/studio_animation/webhook/sample.json');
        $this->assertFileExists($path);
        $raw = (string) file_get_contents($path);
        $secret = 'fixture-secret';
        $sig = hash_hmac('sha256', $raw, $secret);
        $request = Request::create(
            'https://example.test/webhooks/studio-animation/kling',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_FAL_SIGNATURE' => $sig,
            ],
            $raw,
        );
        config(['studio_animation.webhooks.fal_signature_secret' => $secret]);
        $r = (new StudioAnimationWebhookSignatureVerifier)->verifyProviderPayload($request, 'kling');
        $this->assertTrue($r['verified']);
    }
}
