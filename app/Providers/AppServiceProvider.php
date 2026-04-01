<?php

namespace App\Providers;

use App\Http\Middleware\EnsureIncubationWorkspaceNotLocked;
use App\Contracts\ImageEmbeddingServiceInterface;
use App\Events\AssetPendingApproval;
use App\Events\AssetUploaded;
use App\Events\CompanyTransferCompleted;
use App\Listeners\ActivateAgencyReferral;
use App\Listeners\GrantAgencyPartnerReward;
use App\Listeners\ProcessAssetOnUpload;
use App\Listeners\QueueFailureListener;
use App\Listeners\SendAssetPendingApprovalNotification;
use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AI\Providers\AnthropicProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\OpenAIProvider;
use App\Services\ImageEmbeddingService;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Cached routes (route:cache) or rolling deploys may still reference this middleware by string.
        // Without an alias, the container treats "incubation.not_locked" as a class name and throws.
        $this->app->alias(EnsureIncubationWorkspaceNotLocked::class, 'incubation.not_locked');

        $this->app->singleton(AIProviderInterface::class, function ($app) {
            $defaultProviderName = config('ai.default_provider', 'openai');

            return match ($defaultProviderName) {
                'anthropic' => new AnthropicProvider,
                default => new OpenAIProvider,
            };
        });

        $this->app->singleton(AnthropicProvider::class, function ($app) {
            return new AnthropicProvider;
        });

        $this->app->singleton(OpenAIProvider::class, function ($app) {
            return new OpenAIProvider;
        });

        if (config('ai.gemini.api_key')) {
            $this->app->singleton(GeminiProvider::class, function ($app) {
                return new GeminiProvider;
            });
        }

        $this->app->singleton(ImageEmbeddingServiceInterface::class, function () {
            return new ImageEmbeddingService;
        });

        $this->app->singleton(\App\Services\SpatieRoleLookup::class);

        $this->app->singleton(\App\Services\NotificationOrchestrator::class, function ($app) {
            return new \App\Services\NotificationOrchestrator([
                'in_app' => $app->make(\App\Services\Notifications\Channels\InAppChannel::class),
                'email' => $app->make(\App\Services\Notifications\Channels\EmailChannel::class),
                'push' => $app->make(\App\Services\Notifications\Channels\PushChannel::class),
            ]);
        });

        // Request-scoped URL metrics/state for AssetUrlService.
        $this->app->scoped(\App\Services\AssetUrlService::class, function ($app) {
            return new \App\Services\AssetUrlService(
                $app->make(\App\Services\AssetVariantPathResolver::class),
                $app->make(\App\Services\CloudFrontSignedUrlService::class),
                $app->make(\App\Services\TenantBucketService::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Map short actor_type strings for ActivityEvent morphTo resolution
        Relation::morphMap([
            'user' => \App\Models\User::class,
        ]);

        // Validate Vite manifest requirement based on environment
        $this->validateViteManifest();

        // Avoid Symfony IncompleteDsnException: User is not set (Mailtrap SDK requires MAILTRAP_API_KEY).
        $this->fallbackMailtrapSdkWhenApiKeyMissing();

        // Register model observers for automation triggers
        \App\Models\Ticket::observe(\App\Observers\TicketObserver::class);
        \App\Models\TicketMessage::observe(\App\Observers\TicketMessageObserver::class);

        // Metadata schema cache invalidation (tenant-scoped tagged cache)
        \App\Models\MetadataField::observe(\App\Observers\MetadataFieldObserver::class);
        \App\Models\MetadataOption::observe(\App\Observers\MetadataOptionObserver::class);
        \App\Models\MetadataFieldVisibility::observe(\App\Observers\MetadataFieldVisibilityObserver::class);
        \App\Models\MetadataOptionVisibility::observe(\App\Observers\MetadataOptionVisibilityObserver::class);

        \App\Models\BrandModelVersion::observe(\App\Observers\BrandModelVersionObserver::class);

        \App\Models\Asset::observe(\App\Observers\AssetObserver::class);

        // Record last successful session login (web guard) for admin reporting
        Event::listen(Login::class, function (Login $event): void {
            if ($event->guard !== 'web') {
                return;
            }
            $user = $event->user;
            if ($user instanceof \App\Models\User) {
                $user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        // Register event listeners
        Event::listen(AssetUploaded::class, ProcessAssetOnUpload::class);
        Event::listen(AssetUploaded::class, \App\Listeners\BustBrandInsightCache::class);
        Event::listen(AssetPendingApproval::class, SendAssetPendingApprovalNotification::class);

        // Phase AG-4: Agency partner reward attribution
        Event::listen(CompanyTransferCompleted::class, GrantAgencyPartnerReward::class);

        // Phase AG-10: Agency referral activation (attribution only, no rewards)
        Event::listen(CompanyTransferCompleted::class, ActivateAgencyReferral::class);

        // Unified Operations: Capture queue failures for asset-processing jobs
        Event::listen(JobFailed::class, QueueFailureListener::class);

        // Tenant mail branding: reset between queued jobs so long-lived workers do not leak From config.
        Queue::before(static function (): void {
            \App\Support\TenantMailBranding::reset();
        });
        Queue::after(static function (): void {
            \App\Support\TenantMailBranding::reset();
        });

        // Brand insight cache: bust on download/share creation
        \App\Models\Download::created(function ($download) {
            if ($download->brand_id) {
                app(\App\Services\BrandInsightLLM::class)->bustCache($download->brand);
            }
        });

        // Surface N+1 lazy loading in non-production (Sentry/local)
        if (! app()->isProduction()) {
            Model::preventLazyLoading();
        }

        // Forbid Schema::hasColumn() during HTTP lifecycle (causes N+1 information_schema queries).
        // Migrations and console commands may still use it.
        if (app()->runningInConsole() === false) {
            Schema::macro('hasColumn', function () {
                throw new RuntimeException('Schema::hasColumn() is forbidden during HTTP lifecycle.');
            });
        }
    }

    /**
     * Mailtrap Sending API builds a Symfony Mailer DSN whose "user" is the API key
     * ({@see config('services.mailtrap-sdk.apiKey')} from MAILTRAP_API_KEY or MAILTRAP_API_TOKEN).
     * If the default mailer is mailtrap-sdk but the key is empty, sending mail throws
     * {@see \Symfony\Component\Mailer\Exception\IncompleteDsnException} ("User is not set").
     * Fall back to the log driver so flows like forgot-password do not 500; ops should set the key or MAIL_MAILER.
     */
    protected function fallbackMailtrapSdkWhenApiKeyMissing(): void
    {
        if (config('mail.default') !== 'mailtrap-sdk') {
            return;
        }

        $apiKey = config('services.mailtrap-sdk.apiKey');
        if (is_string($apiKey) && trim($apiKey) !== '') {
            return;
        }

        Log::warning(
            'MAIL_MAILER is mailtrap-sdk but Mailtrap API credentials are empty; falling back to log mailer. '
            .'Set MAILTRAP_API_KEY or MAILTRAP_API_TOKEN (https://mailtrap.io/api-tokens) or use MAIL_MAILER=smtp/ses/log.'
        );

        Config::set('mail.default', 'log');
    }

    /**
     * Validate Vite manifest requirement based on environment.
     *
     * - local: Uses Vite dev server, manifest NOT required
     * - staging/production: Manifest MUST exist or exception is thrown
     */
    protected function validateViteManifest(): void
    {
        // 🚫 Never validate during CLI operations (deploy, composer, artisan, CI)
        if (App::runningInConsole()) {
            return;
        }

        // 🧪 Local uses Vite dev server — no manifest required aa
        if (app()->environment('local')) {
            return;
        }

        // 🚨 Staging / Production MUST have built assets
        $manifestPath = public_path('build/manifest.json');

        if (! File::exists($manifestPath)) {
            throw new RuntimeException(
                'Vite build missing. Run npm run build.'
            );
        }
    }
}
