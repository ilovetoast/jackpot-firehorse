<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

final class Dam3dBlenderBinaryConfigTest extends TestCase
{
    /** Aligns Sail Dockerfile (/usr/local/bin/blender) with config default when env is unset. */
    public function test_config_file_defaults_blender_binary_to_sail_install_path(): void
    {
        $php = (string) file_get_contents(config_path('dam_3d.php'));
        $this->assertStringContainsString(
            "env('DAM_3D_BLENDER_BINARY', '/usr/local/bin/blender')",
            $php
        );
        $this->assertStringContainsString("env('DAM_3D_REAL_RENDER_ENABLED', true)", $php);
    }
}
