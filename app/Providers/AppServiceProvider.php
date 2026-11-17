<?php

namespace App\Providers;

use App\Application\Ports\GenerationRepository;
use App\Application\Ports\ImageProvider;
use App\Infrastructure\Persistence\EloquentGenerationRepository;
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
