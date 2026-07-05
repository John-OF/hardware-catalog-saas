<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

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
        RateLimiter::for('anonymous_reviews', function (Request $request) {
            if ($request->user('sanctum')) {
                return Limit::none();
            }
            // Límite de 60 por hora en desarrollo para facilitar pruebas cómodas
            return Limit::perHour(60)->by($request->ip());
        });
    }
}
