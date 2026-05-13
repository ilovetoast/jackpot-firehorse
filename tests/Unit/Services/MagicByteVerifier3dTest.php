<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Security\MagicByteVerifier;
use Tests\TestCase;

final class MagicByteVerifier3dTest extends TestCase
{
    private MagicByteVerifier $v;

    protected function setUp(): void
    {
        parent::setUp();
        $this->v = new MagicByteVerifier;
    }

    public function test_glb_passes_model_gltf_binary(): void
    {
        $head = "glTF\x02\x00\x00\x00".str_repeat("\0", 32);
        $this->assertSame('glb', $this->v->detectSignature($head));
        $this->assertTrue($this->v->verify($head, 'model/gltf-binary')['ok']);
    }

    public function test_obj_wavefront_passes(): void
    {
        $head = "v 1 0 0\nf 1 2 3\n";
        $this->assertSame('obj_wavefront', $this->v->detectSignature($head));
        $this->assertTrue($this->v->verify($head, 'model/obj')['ok']);
    }

    public function test_stl_binary_passes(): void
    {
        $header = str_pad('binary stl file', 80, ' ');
        $tri = pack('V', 1);
        $head = $header.$tri.str_repeat("\0", 50);
        $this->assertSame('stl_binary', $this->v->detectSignature($head));
        $this->assertTrue($this->v->verify($head, 'model/stl')['ok']);
    }

    public function test_stl_ascii_passes(): void
    {
        $head = "solid cube\nfacet normal 0 0 1\nendsolid\n";
        $this->assertSame('stl_ascii', $this->v->detectSignature($head));
        $this->assertTrue($this->v->verify($head, 'model/stl')['ok']);
    }

    public function test_html_rejected_as_glb(): void
    {
        $head = "<!DOCTYPE html>\n<html><body>x</body></html>";
        $this->assertNull($this->v->detectSignature($head));
        $this->assertFalse($this->v->verify($head, 'model/gltf-binary')['ok']);
    }

    public function test_gltf_json_passes(): void
    {
        $head = '{"asset":{"version":"2.0"},"buffers":[]}';
        $this->assertSame('gltf_json', $this->v->detectSignature($head));
        $this->assertTrue($this->v->verify($head, 'model/gltf+json')['ok']);
    }
}
