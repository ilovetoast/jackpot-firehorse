<?php

namespace App\Providers;

use App\Events\AssetPendingApproval;
use App\Events\AssetUploaded;
use App\Events\CompanyTransferCompleted;
use App\Listeners\ActivateAgencyReferral;
use App\Listeners\GrantAgencyPartnerReward;
use App\Listeners\ProcessAssetOnUpload;
use App\Listeners\SendAssetPendingApprovalNotification;
use App\Contracts\ImageEmbeddingServiceInterface;
use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AI\Providers\OpenAIProvider;
use App\Services\ImageEmbeddingService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Illuminate\Support\Facades\App;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind AI provider interface to default provider
        // This allows dependency injection of AIProviderInterface
        // The default provider is resolved from config('ai.default_provider')
        $this->app->singleton(AIProviderInterface::class, function ($app) {
            $defaultProviderName = config('ai.default_provider', 'openai');
            
            // Resolve provider based on config
            // Currently only OpenAI is implemented
            if ($defaultProviderName === 'openai') {
                return new OpenAIProvider();
            }
            
            // Fallback to OpenAI if provider not found
            return new OpenAIProvider();
        });

        $this->app->singleton(ImageEmbeddingServiceInterface::class, function () {
            return new ImageEmbeddingService();
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

        // Register model observers for automation triggers
        \App\Models\Ticket::observe(\App\Observers\TicketObserver::class);
        \App\Models\TicketMessage::observe(\App\Observers\TicketMessageObserver::class);

        // Register event listeners
        Event::listen(AssetUploaded::class, ProcessAssetOnUpload::class);
        Event::listen(AssetPendingApproval::class, SendAssetPendingApprovalNotification::class);
        
        // Phase AG-4: Agency partner reward attribution
        Event::listen(CompanyTransferCompleted::class, GrantAgencyPartnerReward::class);
        
        // Phase AG-10: Agency referral activation (attribution only, no rewards)
        Event::listen(CompanyTransferCompleted::class, ActivateAgencyReferral::class);
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
