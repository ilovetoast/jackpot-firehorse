<?php

namespace App\Providers;

use App\Http\Middleware\EnsureIncubationWorkspaceNotLocked;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Support\SentryTracesSampler;
use Illuminate\Routing\Router;
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
use Sentry\ClientBuilder;
use Sentry\Event as SentryEvent;
use Sentry\EventHint;
use Sentry\EventType;
use Sentry\State\HubInterface;
use Sentry\State\Scope;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // PrivacyRegionResolver: some releases / cached Blade may resolve this class. If the PSR-4 file
        // is missing from a partial deploy, fall back to helpers so app.blade.php never fatals.
        $this->app->singleton(\App\Services\Privacy\PrivacyRegionResolver::class, function () {
            if (class_exists(\App\Services\Privacy\PrivacyRegionResolver::class)) {
                return new \App\Services\Privacy\PrivacyRegionResolver;
            }

            return new class
            {
                public function countryCodeFromRequest(\Illuminate\Http\Request $request): ?string
                {
                    return privacy_region_country_code($request);
                }

                public function needsStrictOptIn(?string $iso2): bool
                {
                    return privacy_needs_strict_opt_in($iso2);
                }

                public function globalPrivacyControl(\Illuminate\Http\Request $request): bool
                {
                    return privacy_global_gpc($request);
                }
            };
        });

        // Cached routes (route:cache) or rolling deploys may still reference these middleware by string.
        // Without a container alias, the router's fallback path treats the string as a class name and
        // Container::build throws "Target class [...] does not exist". The $middleware->alias() map in
        // bootstrap/app.php handles the normal path; these are belt-and-suspenders for rolling deploys
        // and stale OPcache where that map hasn't reloaded yet.
        $this->app->alias(EnsureIncubationWorkspaceNotLocked::class, 'incubation.not_locked');
        $this->app->alias(EnsureOnboardingComplete::class, 'ensure.onboarding');

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

        $this->app->singleton(
            \App\Contracts\StudioCanvasRuntimePlaywrightInvokerContract::class,
            \App\Services\Studio\DefaultStudioCanvasRuntimePlaywrightInvoker::class,
        );

        $this->app->singleton(
            \App\Contracts\StudioCanvasRuntimeFfmpegProcessInvokerContract::class,
            \App\Services\Studio\DefaultStudioCanvasRuntimeFfmpegProcessInvoker::class,
        );

        $this->app->bind(
            \App\Studio\Rendering\Contracts\CompositionRenderer::class,
            \App\Studio\Rendering\FfmpegNativeCompositionRenderer::class,
        );

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

        // Sentry: dynamic trace sampling (config-cache-safe invokable, not a closure in config/sentry.php).
        // Skip when SENTRY_TRACES_SAMPLE_RATE is set so ops can force a flat rate without code changes.
        $this->app->afterResolving(ClientBuilder::class, function (ClientBuilder $builder): void {
            if (env('SENTRY_TRACES_SAMPLE_RATE') !== null && env('SENTRY_TRACES_SAMPLE_RATE') !== '') {
                return;
            }
            if (empty(config('sentry.dsn')) && ! config('sentry.spotlight')) {
                return;
            }
            $builder->getOptions()->setTracesSampler(new SentryTracesSampler);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Router middleware aliases: registered here in addition to bootstrap/app.php so a stale
        // route:cache or release whose bootstrap/app.php is out of sync still resolves these names.
        // Complements the container aliases set in register() (those rescue the router's fallback
        // path through Container::make($name); this rescues the router's primary lookup path).
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('incubation.not_locked', EnsureIncubationWorkspaceNotLocked::class);
        $router->aliasMiddleware('ensure.onboarding', EnsureOnboardingComplete::class);

        $this->hydrateStudioRenderingDefaultFontPath();

        // Sentry: prevent huge transaction span explosions from inflating quota (drops the transaction only, not errors).
        if ($this->app->bound(HubInterface::class)) {
            \Sentry\configureScope(static function (Scope $scope): void {
                $scope->addEventProcessor(static function (SentryEvent $event, ?EventHint $hint): ?SentryEvent {
                    if ($event->getType() !== EventType::transaction()) {
                        return $event;
                    }
                    $spans = $event->getSpans();
                    if (count($spans) > 100) {
                        return null;
                    }

                    return $event;
                });
            });
        }

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

        \App\Models\TenantModule::observe(\App\Observers\TenantModuleObserver::class);

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
     * Stale `php artisan config:cache` artifacts may omit `default_font_path` under `studio_rendering`
     * while STUDIO_RENDERING_DEFAULT_FONT_PATH is present in the process environment. Ensure
     * {@see config('studio_rendering.default_font_path')} is always a non-empty string at runtime.
     */
    private function hydrateStudioRenderingDefaultFontPath(): void
    {
        $current = config('studio_rendering.default_font_path');
        if ($current !== null && trim((string) $current) !== '') {
            return;
        }
        $fromEnv = env('STUDIO_RENDERING_DEFAULT_FONT_PATH');
        $resolved = trim((string) (is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'));
        Config::set('studio_rendering.default_font_path', $resolved);
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
