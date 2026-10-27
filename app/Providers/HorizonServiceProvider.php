<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Horizon is optional. Windows PHP has no pcntl, and WSL may not have
 * `composer install` yet. This provider no-ops until the package exists.
 */
class HorizonServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(\Laravel\Horizon\Horizon::class)) {
            return;
        }

        $this->gate();

        \Laravel\Horizon\Horizon::auth(function ($request) {
            return Gate::check('viewHorizon', [$request->user()])
                || $this->app->environment('local');
        });

        \Laravel\Horizon\Horizon::night();
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if ($this->app->environment('local')) {
                return true;
            }

            return $user && $user->email === env('HORIZON_EMAIL');
        });
    }
}
