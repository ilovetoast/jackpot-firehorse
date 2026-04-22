<?php

namespace App\Console\Commands;

use App\Studio\Animation\Providers\Kling\KlingNativeClient;
use Illuminate\Console\Command;

/**
 * Smoke-test the official Kling image-to-video API (JWT: KLING_API_KEY + KLING_SECRET_KEY).
 */
final class StudioAnimationTestKlingConnectionCommand extends Command
{
    protected $signature = 'studio-animation:test-kling-connection';

    protected $description = 'POST a minimal image-to-video job to the official Kling API (connectivity)';

    public function handle(): int
    {
        $transport = (string) config('studio_animation.providers.kling.transport', 'kling_api');
        if ($transport === 'mock') {
            $this->error('Kling transport is mock — this command requires the real Kling API (set studio_animation.providers.kling.transport to kling_api in test env, or use production config).');

            return self::FAILURE;
        }

        if ($transport !== 'kling_api') {
            $this->error("Only kling_api is supported; got: {$transport}");

            return self::FAILURE;
        }

        return $this->testKlingNative();
    }

    private function testKlingNative(): int
    {
        $cfg = config('studio_animation.providers.kling.native', []);
        $access = (string) ($cfg['access_key'] ?? '');
        $secret = (string) ($cfg['secret_key'] ?? '');
        if ($access === '' || $secret === '') {
            $this->error('Set KLING_API_KEY (Access Key) and KLING_SECRET_KEY for official Kling API.');

            return self::FAILURE;
        }

        $base = (string) ($cfg['base_url'] ?? 'https://api-singapore.klingai.com');
        $model = (string) ($cfg['default_model'] ?? 'kling-v2-5-turbo');
        $this->line('base_url:   '.$base);
        $this->line('model:      '.$model);
        $this->line('access_key: '.strlen($access).' characters');
        $this->line('secret_key: '.strlen($secret).' characters');
        $this->newLine();

        $pngB64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $payload = [
            'model_name' => $model,
            'image' => $pngB64,
            'prompt' => 'CLI connectivity test; ignore.',
            'duration' => '5',
            'mode' => 'std',
            'aspect_ratio' => '1:1',
            'sound' => 'off',
        ];
        $client = new KlingNativeClient($access, $secret, $base);
        $r = $client->postImage2Video($payload);
        if (! ($r['ok'] ?? false)) {
            $this->error('Submit failed.');
            $this->line('error: '.($r['error'] ?? ''));
            $msg = (string) ($r['message'] ?? '');
            if (strlen($msg) > 2000) {
                $msg = substr($msg, 0, 2000).'…';
            }
            $this->line($msg);

            return self::FAILURE;
        }

        $this->info('Submit accepted.');
        $this->line('task_id: '.(string) ($r['task_id'] ?? ''));
        $this->newLine();
        $this->comment('This does not wait for the video. Check status in the Studio UI or your Kling dashboard.');

        return self::SUCCESS;
    }
}
