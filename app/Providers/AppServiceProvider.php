<?php

namespace App\Providers;

use App\Events\AssetPendingApproval;
use App\Events\AssetUploaded;
use App\Listeners\ProcessAssetOnUpload;
use App\Listeners\SendAssetPendingApprovalNotification;
use App\Services\AI\Contracts\AIProviderInterface;
use App\Services\AI\Providers\OpenAIProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model observers for automation triggers
        \App\Models\Ticket::observe(\App\Observers\TicketObserver::class);
        \App\Models\TicketMessage::observe(\App\Observers\TicketMessageObserver::class);

        // Register event listeners
        Event::listen(AssetUploaded::class, ProcessAssetOnUpload::class);
        Event::listen(AssetPendingApproval::class, SendAssetPendingApprovalNotification::class);
    }
}
