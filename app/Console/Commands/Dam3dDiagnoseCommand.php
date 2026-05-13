<?php

namespace App\Console\Commands;

use App\Services\Models\BlenderModelPreviewService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class Dam3dDiagnoseCommand extends Command
{
    protected $signature = 'dam:3d:diagnose';

    protected $description = 'Report DAM_3D / Blender worker diagnostics (non-fatal if Blender is missing).';

    public function handle(): int
    {
        $dam3d = (bool) config('dam_3d.enabled');
        $this->line('DAM_3D enabled: '.($dam3d ? 'yes' : 'no'));

        $sail = filter_var(env('LARAVEL_SAIL', false), FILTER_VALIDATE_BOOLEAN);
        $this->line('Laravel Sail (LARAVEL_SAIL): '.($sail ? 'yes' : 'no'));
        $this->line('Likely Docker worker runtime: '.(is_file('/.dockerenv') ? 'yes (.dockerenv present)' : 'unknown'));

        $bin = BlenderModelPreviewService::blenderBinaryConfigured();
        $this->line('Blender binary (config): '.$bin);

        $which = '';
        try {
            $pw = new Process(['/bin/sh', '-c', 'command -v blender 2>/dev/null || true'], sys_get_temp_dir(), null, null, 5.0);
            $pw->run();
            $which = trim($pw->getOutput());
        } catch (\Throwable) {
            $which = '';
        }
        $this->line('PATH blender (command -v): '.($which !== '' ? $which : 'not found'));

        $exists = is_file($bin);
        $this->line('Blender exists: '.($exists ? 'yes' : 'no'));

        $exe = $exists && is_executable($bin);
        $this->line('Blender executable: '.($exe ? 'yes' : 'no'));

        $ver = 'n/a';
        if ($exe) {
            try {
                $p = new Process([$bin, '-b', '--version'], sys_get_temp_dir(), null, null, 20.0);
                $p->run();
                $ver = $p->isSuccessful() ? trim(strtok($p->getOutput(), "\n") ?: '') : 'unknown (command failed)';
            } catch (\Throwable $e) {
                $ver = 'error: '.$e->getMessage();
            }
        }
        $this->line('Blender version: '.$ver);

        $tmp = sys_get_temp_dir();
        $w = is_dir($tmp) && is_writable($tmp);
        $this->line('Temp directory writable: '.($w ? 'yes' : 'no').' ('.$tmp.')');

        $q = config('dam_3d.preview_queue');
        $queueName = is_string($q) && trim($q) !== '' ? trim($q) : (string) config('queue.images_heavy_queue', 'images-heavy');
        $this->line('Configured 3D preview queue: '.$queueName);

        $this->line('max_server_render_bytes: '.(int) config('dam_3d.max_server_render_bytes', 0));
        $this->line('max_render_seconds: '.(float) config('dam_3d.max_render_seconds', 0));
        $this->line('max_conversion_seconds: '.(float) config('dam_3d.max_conversion_seconds', 0));
        $this->line('real_render_enabled: '.(config('dam_3d.real_render_enabled', true) ? 'yes' : 'no'));
        $this->line('conversion_enabled: '.((bool) config('dam_3d.conversion_enabled', false) ? 'yes' : 'no'));

        $script = resource_path('blender/render_model_preview.py');
        $this->line('Bundled Blender script: '.(is_file($script) ? 'present' : 'missing'));

        return self::SUCCESS;
    }
}
