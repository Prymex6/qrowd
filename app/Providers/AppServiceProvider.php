<?php

namespace App\Providers;

use App\Services\CatalogImporter;
use App\Services\HotPay;
use App\Services\MusicSearch;
use App\Services\YouTube\QuotaGuard;
use App\Services\YouTube\YouTubeClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // HotPay takes its secret and password from the configuration - the
        // container cannot resolve string arguments by itself, so without this
        // binding it would get empty ones and every notification would be
        // rejected as forged.
        $this->app->bind(HotPay::class, fn () => HotPay::make());

        // These classes need configuration (the API key, the quota thresholds),
        // so the container cannot build them by itself - we give it the recipe.
        $this->app->singleton(QuotaGuard::class, fn () => QuotaGuard::fromConfig());
        $this->app->singleton(YouTubeClient::class, fn () => YouTubeClient::make());
        $this->app->singleton(MusicSearch::class, fn () => MusicSearch::make());
        $this->app->singleton(CatalogImporter::class, fn () => CatalogImporter::make());
    }

    public function boot(): void
    {
        //
    }
}
