<?php

namespace App\Providers;

use App\Application\Ports\GenerationRepository;
use App\Application\Ports\ImageProvider;
use App\Application\Ports\PricingSource;
use App\Domain\Pricing\MarkupPolicy;
use App\Infrastructure\Persistence\EloquentGenerationRepository;
use App\Infrastructure\Pixelforge\CachedPricingSource;
use App\Infrastructure\Pixelforge\PixelforgeClient;
use App\Infrastructure\Pixelforge\PixelforgeSettings;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GenerationRepository::class, EloquentGenerationRepository::class);

        $this->app->singleton(MarkupPolicy::class, fn () => MarkupPolicy::percent((int) config('billing.markup_percent')));

        $this->app->bind(PricingSource::class, fn ($app) => new CachedPricingSource(
            $app->make(ImageProvider::class),
            $app->make('cache.store'),
            (int) config('services.pixelforge.pricing_cache_seconds'),
        ));

        $this->app->bind(ImageProvider::class, function () {
            if (blank(config('services.pixelforge.api_key'))) {
                throw new RuntimeException('PIXELFORGE_API_KEY is not set.');
            }

            return new PixelforgeClient(PixelforgeSettings::fromConfig(config('services.pixelforge')));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
