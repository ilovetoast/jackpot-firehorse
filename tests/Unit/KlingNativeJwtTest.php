<?php

namespace Tests\Unit;

use App\Studio\Animation\Providers\Kling\KlingNativeJwt;
use Tests\TestCase;

class KlingNativeJwtTest extends TestCase
{
    public function test_sign_produces_three_jwt_segments(): void
    {
        $token = KlingNativeJwt::sign('access_key_example', 'secret_key_example');
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
        $this->assertNotSame('', $parts[0]);
        $this->assertNotSame('', $parts[1]);
        $this->assertNotSame('', $parts[2]);
    }
}
