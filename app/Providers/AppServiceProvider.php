<?php

namespace App\Providers;

use App\Http\Middleware\VerifyTwilioSignature;
use App\Services\OpenAI\RealtimeClientFactory;
use App\Services\OpenAI\RealtimeSessionConfigurator;
use App\Services\OpenAI\TranscriptProcessor;
use App\Services\Twilio\TwilioSessionManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RealtimeClientFactory::class);
        $this->app->singleton(RealtimeSessionConfigurator::class);
        $this->app->singleton(TranscriptProcessor::class);
        $this->app->singleton(TwilioSessionManager::class);
        $this->app->singleton(VerifyTwilioSignature::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
