<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Sends a single test push via OneSignal REST API (same contract as PushChannel: external_id user_{id}).
 *
 * Usage: php artisan onesignal:test-push 42
 *        php artisan onesignal:test-push 42 --force
 */
class OneSignalTestPushCommand extends Command
{
    protected $signature = 'onesignal:test-push
                            {user_id : Numeric user id (OneSignal external_id will be user_{id})}
                            {--force : Send even when PUSH_NOTIFICATIONS_ENABLED is false}
                            {--title=Jackpot test : Notification title}
                            {--message=OneSignal API test from artisan : Notification body}';

    protected $description = 'Send a one-off test web push via OneSignal REST API (requires ONESIGNAL_* in .env)';

    public function handle(): int
    {
        $pushEnabled = filter_var(env('PUSH_NOTIFICATIONS_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        if (! $pushEnabled && ! $this->option('force')) {
            $this->error('PUSH_NOTIFICATIONS_ENABLED is false. Set it true or pass --force to send a test anyway.');

            return self::FAILURE;
        }

        $appId = config('services.onesignal.app_id');
        $apiKey = config('services.onesignal.rest_api_key');
        if (empty($appId) || empty($apiKey)) {
            $this->error('Missing ONESIGNAL_APP_ID or ONESIGNAL_REST_API_KEY in environment / config/services.php.');

            return self::FAILURE;
        }

        $userId = (int) $this->argument('user_id');
        if ($userId <= 0) {
            $this->error('user_id must be a positive integer.');

            return self::FAILURE;
        }

        $user = User::query()->find($userId);
        if (! $user) {
            $this->error("User not found: {$userId}");

            return self::FAILURE;
        }

        $externalId = 'user_'.$userId;
        $title = (string) $this->option('title');
        $message = (string) $this->option('message');

        $body = [
            'app_id' => $appId,
            'target_channel' => 'push',
            'include_aliases' => [
                'external_id' => [$externalId],
            ],
            'headings' => ['en' => $title],
            'contents' => ['en' => $message],
            'data' => [
                'event' => 'onesignal.test',
                'user_id' => (string) $userId,
            ],
        ];

        $this->info("Targeting external_id: {$externalId} ({$user->email})");

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Key '.$apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post('https://api.onesignal.com/notifications', $body);
        } catch (\Throwable $e) {
            $this->error('Request failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('HTTP '.$response->status());
        $decoded = $response->json();
        if (is_array($decoded)) {
            $this->line(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line($response->body());
        }

        if (! $response->successful()) {
            return self::FAILURE;
        }

        $messageId = is_array($decoded) ? ($decoded['id'] ?? null) : null;
        $apiErrors = is_array($decoded) ? ($decoded['errors'] ?? []) : [];
        $hasMessageId = is_string($messageId) && $messageId !== '';
        $hasApiErrors = is_array($apiErrors) && $apiErrors !== [];

        // OneSignal often returns HTTP 200 with empty id + errors when the request is valid but nobody is subscribed.
        if ($hasApiErrors || ! $hasMessageId) {
            $this->newLine();
            $this->warn('No message was queued for delivery (no matching subscribed devices for this external_id).');
            if ($hasApiErrors) {
                foreach ($apiErrors as $err) {
                    $this->line('  • '.(is_string($err) ? $err : json_encode($err)));
                }
            }
            $this->newLine();
            $this->line('Typical fixes for “not subscribed”:');
            $this->line('  1. User opens the app on the SAME OneSignal app (correct ONESIGNAL_APP_ID + Site URL in the OneSignal dashboard).');
            $this->line('  2. PUSH_NOTIFICATIONS_ENABLED=true so the SDK loads; user is logged in so OneSignal.login("user_'.$userId.'") runs.');
            $this->line('  3. User allows browser notifications (or uses “Enable notifications” if you add that control).');
            $this->line('  4. Service workers load from /OneSignalSDKWorker.js — hard-refresh or revisit after deploy.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Message accepted; check a subscribed device for the push.');

        return self::SUCCESS;
    }
}
