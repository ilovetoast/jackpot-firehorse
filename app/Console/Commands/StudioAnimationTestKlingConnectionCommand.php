<?php

namespace App\Console\Commands;

use App\Studio\Animation\Providers\Kling\FalKlingQueueTransport;
use App\Studio\Animation\Providers\Kling\KlingNativeClient;
use Illuminate\Console\Command;

/**
 * Smoke-test the configured Kling transport (official API or fal).
 */
final class StudioAnimationTestKlingConnectionCommand extends Command
{
    protected $signature = 'studio-animation:test-kling-connection';

    protected $description = 'POST a minimal image-to-video job (kling_api = official Kling; fal_queue = fal.ai)';

    public function handle(): int
    {
        $transport = (string) config('studio_animation.providers.kling.transport', 'kling_api');
        if ($transport === 'mock') {
            $this->error('STUDIO_ANIMATION_KLING_TRANSPORT is mock — set kling_api or fal_queue to test a real API.');

            return self::FAILURE;
        }

        if ($transport === 'kling_api') {
            return $this->testKlingNative();
        }

        if ($transport === 'fal_queue') {
            return $this->testFal();
        }

        $this->error("Unknown STUDIO_ANIMATION_KLING_TRANSPORT: {$transport}");

        return self::FAILURE;
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

    private function testFal(): int
    {
        $apiKey = (string) config('studio_animation.providers.kling.fal.api_key', '');
        if ($apiKey === '') {
            $this->error('No FAL_KEY: set FAL_KEY in .env for fal_queue transport.');

            return self::FAILURE;
        }

        $base = rtrim((string) config('studio_animation.providers.kling.fal.queue_base_url', 'https://queue.fal.run'), '/');
        $model = (string) config('studio_animation.providers.kling.fal.model_path', 'fal-ai/kling-video/v3/standard/image-to-video');

        $this->line('queue_base_url: '.$base);
        $this->line('model_path:     '.$model);
        $this->line('FAL key:        '.strlen($apiKey).' characters');
        $this->newLine();

        $pngB64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $dataUri = 'data:image/png;base64,'.$pngB64;
        $input = [
            'prompt' => 'CLI connectivity test; ignore.',
            'start_image_url' => $dataUri,
            'duration' => '5',
            'generate_audio' => false,
        ];

        $t = new FalKlingQueueTransport;
        $r = $t->submit($model, $input, $apiKey, $base);

        if (! ($r['ok'] ?? false)) {
            $this->error('Submit failed.');
            $this->line('error: '.($r['error'] ?? ''));
            $msg = (string) ($r['message'] ?? '');
            if (strlen($msg) > 2000) {
                $msg = substr($msg, 0, 2000).'…';
            }
            $this->line($msg);
            if (($r['error'] ?? '') === 'submit_http_401' || str_contains(strtolower($msg), 'authentication')) {
                $this->newLine();
                $this->warn('fal uses Authorization: Key with FAL_KEY. For Kling’s Access+Secret, use STUDIO_ANIMATION_KLING_TRANSPORT=kling_api.');
            }

            return self::FAILURE;
        }

        $this->info('Submit accepted.');
        $this->line('request_id:  '.(string) ($r['request_id'] ?? ''));
        $this->line('status_url:  '.(string) ($r['status_url'] ?? ''));
        $this->line('response_url: '.(string) ($r['response_url'] ?? ''));
        $this->newLine();
        $this->comment('This does not wait for the video. Use the Studio UI or poll status_url to verify completion.');

        return self::SUCCESS;
    }
}
