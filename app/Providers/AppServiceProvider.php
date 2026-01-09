<?php

namespace App\Providers;

use App\Events\AssetUploaded;
use App\Listeners\ProcessAssetOnUpload;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
    }
}
