<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Shows chrome_web_origin and other web fields from OneSignal for the configured App ID.
 *
 * The Web SDK error "Can only be used on: …" compares window.location to this origin.
 * Changing ONESIGNAL_REST_API_KEY does not change the allowed origin — only the app record in OneSignal does.
 *
 * View-an-app may require an Organization API key; REST API key is tried as fallback.
 *
 * @see https://documentation.onesignal.com/reference/view-an-app
 */
class OneSignalVerifyAppCommand extends Command
{
    protected $signature = 'onesignal:verify-app';

    protected $description = 'Print OneSignal app web origin (chrome_web_origin) for ONESIGNAL_APP_ID — debug domain mismatch';

    public function handle(): int
    {
        $appId = config('services.onesignal.app_id');
        if (empty($appId)) {
            $this->error('ONESIGNAL_APP_ID is not set.');

            return self::FAILURE;
        }

        $orgKey = config('services.onesignal.organization_api_key');
        $restKey = config('services.onesignal.rest_api_key');

        $tried = [];
        foreach (['organization' => $orgKey, 'REST (app)' => $restKey] as $label => $key) {
            if (empty($key)) {
                continue;
            }
            $tried[] = $label;
            $response = Http::timeout(20)
                ->withHeaders([
                    'Authorization' => 'Key '.$key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->get('https://api.onesignal.com/apps/'.$appId);

            if ($response->status() === 200) {
                $json = $response->json();
                if (! is_array($json)) {
                    $this->error('Unexpected response body.');

                    return self::FAILURE;
                }

                $this->info('App ID: '.$appId.' (authorized with '.$label.' key)');
                $this->newLine();
                $this->line('Web origin (must match browser URL, e.g. https://staging-jackpot.velvetysoft.com):');
                $this->line('  chrome_web_origin: '.($json['chrome_web_origin'] ?? '(missing)'));
                $this->line('  site_name:         '.($json['site_name'] ?? '(missing)'));
                $this->line('  name:              '.($json['name'] ?? '(missing)'));
                if (! empty($json['chrome_web_sub_domain'])) {
                    $this->line('  chrome_web_sub_domain: '.$json['chrome_web_sub_domain']);
                }
                $this->newLine();
                $this->line('APP_URL (Laravel): '.(string) config('app.url'));
                $this->newLine();
                $this->comment(
                    'If chrome_web_origin still shows the wrong host (.co vs .com), update Site URL in the OneSignal dashboard '.
                    'for this exact App ID (Keys & IDs), or set ONESIGNAL_ORGANIZATION_API_KEY and fix the app via API/dashboard.'
                );

                return self::SUCCESS;
            }

            if ($response->status() === 401 || $response->status() === 403) {
                $this->warn($label.' key: HTTP '.$response->status().' — trying next key if any.');

                continue;
            }

            $this->error('HTTP '.$response->status());
            $this->line($response->body());

            return self::FAILURE;
        }

        if ($tried === []) {
            $this->error('Set ONESIGNAL_REST_API_KEY or ONESIGNAL_ORGANIZATION_API_KEY to call the OneSignal API.');

            return self::FAILURE;
        }

        $this->error('Could not read app (401/403). OneSignal "View an app" usually needs an Organization API key.');
        $this->line('Add ONESIGNAL_ORGANIZATION_API_KEY to .env (Organization → Keys & IDs in OneSignal), then run again.');

        return self::FAILURE;
    }
}
