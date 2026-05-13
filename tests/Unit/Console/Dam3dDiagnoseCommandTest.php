<?php

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class Dam3dDiagnoseCommandTest extends TestCase
{
    public function test_diagnose_runs_without_blender(): void
    {
        config(['dam_3d.enabled' => false, 'dam_3d.blender_binary' => '/nonexistent/dam3d-blender-test']);
        $code = Artisan::call('dam:3d:diagnose');
        $this->assertSame(0, $code);
        $out = Artisan::output();
        $this->assertStringContainsString('DAM_3D enabled:', $out);
        $this->assertStringContainsString('Laravel Sail', $out);
        $this->assertStringContainsString('Likely Docker worker runtime', $out);
        $this->assertStringContainsString('PATH blender (command -v):', $out);
        $this->assertStringContainsString('Blender exists:', $out);
        $this->assertStringContainsString('Bundled Blender script:', $out);
        $this->assertStringContainsString('interactive viewer', $out);
    }
}
