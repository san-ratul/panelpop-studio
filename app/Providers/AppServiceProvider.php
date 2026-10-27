<?php

namespace App\Providers;

use App\AI\Cost\BudgetGuard;
use App\AI\CredentialStore;
use App\AI\Fakes\FakeProviderScenario;
use App\AI\ProviderCallRecorder;
use App\AI\ProviderGateway;
use App\AI\ProviderManager;
use App\AI\ProviderRegistry;
use App\AI\ProviderSettingsService;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(CredentialStore::class);
        $this->app->singleton(ProviderManager::class);
        $this->app->singleton(ProviderCallRecorder::class);
        $this->app->singleton(BudgetGuard::class);
        $this->app->singleton(ProviderGateway::class);
        $this->app->singleton(ProviderSettingsService::class);

        $this->app->bind(LoggerInterface::class, function (): LoggerInterface {
            return log()->channel(config('ai.log_channel') ?? config('logging.default'));
        });

        if ($this->app->environment('testing')) {
            $this->app->singleton(FakeProviderScenario::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
