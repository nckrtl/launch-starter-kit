<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventAccessingMissingAttributes();
        Model::unguard();

        if ($this->app->environment('testing')) {
            Vite::useHotFile(storage_path('framework/testing/vite.hot'));
        }

        if ($this->app->isProduction()) {
            Vite::prefetch();
        }
    }
}
